<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Reporting;

use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\PluginHealth\CallableKeyChecker;
use Supertext\PluginHealth\InstallationReporter;
use Supertext\PluginHealth\KeyCheck;
use Supertext\PluginHealth\PingResponse;
use Supertext\PluginHealth\ReporterResult;
use Supertext\PluginHealth\ReporterState;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\Supertext;
use Supertext\Sdk\V1\Client;
use Supertext\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the
 * callback ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol).
 * The protocol is Supertext Plugin Health's InstallationReporter (lib/supertext-plugin-health), with
 * the Supertext SDK's checkApiKey() as its key check; this class gives it what Akeneo knows (site
 * URL, Akeneo and bundle versions) and keeps its state in pim_configuration
 * (row "supertext_translation_reporter", through Settings).
 *
 * Nothing here ever throws: a report or a key check must never break a save or a translation.
 * Has no Akeneo or Symfony dependencies (unit-tested with a fake transport).
 */
final class Reporting
{
    /** The GitHub repository name: the center reads the latest release from github.com/Supertext/<name>. */
    public const PLUGIN_NAME = 'Akeneo-Supertext-Translation';
    public const SYSTEM_NAME = 'Akeneo PIM';
    /** Path of the public ping route below the site URL (answered by ReportingSubscriber). */
    public const PING_PATH = '/supertext/ping';

    public const ON_REQUEST = 'onRequest';
    public const AFTER_SETTINGS_SAVED = 'afterSettingsSaved';
    public const TEST_CONNECTION = 'testConnection';
    public const AUTHENTICATION_FAILED = 'authenticationFailed';

    /** English key check messages (console). The UI has them in four languages (keycheck.* in jsmessages). */
    public const CHECK_MESSAGES = [
        KeyCheck::OK          => 'API key checked: the connection to Supertext works.',
        KeyCheck::INVALID     => 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at supertext.com → Integrations → API (requires the Admin role). No Supertext account yet? Create one at supertext.com.',
        KeyCheck::MISSING     => 'No API key is set yet. No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role).',
        KeyCheck::UNREACHABLE => "The API key couldn't be checked because Supertext can't be reached. The settings are saved; check again later.",
        KeyCheck::ERROR       => "The API key couldn't be checked: Supertext answered with an error ({status}). The settings are saved; check again later.",
    ];

    /** @var list<\Closure(): void> */
    private array $deferred = [];

    /**
     * @param HttpTransport|null       $transport     for tests: the key check's and the report's HTTP, as an SDK transport (default: curl)
     * @param string|null              $systemVersion for tests: the Akeneo version (default: from Composer)
     * @param (\Closure(): float)|null  $now           for tests: wall-clock Unix time
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ?HttpTransport $transport = null,
        private readonly ?string $systemVersion = null,
        private readonly ?string $pluginVersion = null,
        private readonly ?\Closure $now = null,
    ) {
    }

    public function enabled(): bool
    {
        try {
            return $this->settings->versionReportsEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /** The ping route's answer (status, headers and body exactly as Plugin Health builds them). */
    public function ping(?string $challenge): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, $this->enabled());
    }

    public static function pingUrl(string $siteUrl): string
    {
        return rtrim($siteUrl, '/') . self::PING_PATH;
    }

    /** The installed Akeneo PIM version (Composer), or null. */
    public static function akeneoVersion(): ?string
    {
        try {
            foreach (['akeneo/pim-community-dev', 'akeneo/pim-community-standard'] as $package) {
                if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package)) {
                    $version = ltrim((string) \Composer\InstalledVersions::getPrettyVersion($package), 'v');

                    if ($version !== '') {
                        return $version;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Plugin Health's reporter for this site, with the SDK's key check against the chosen API. Without a site URL (a console command without AKENEO_PIM_URL)
     * reports are off, but the key check still runs.
     *
     * @param string|null $apiBaseUrl the Supertext API to check the key against (default: the configured one)
     */
    /**
     * @param (callable(): list<array{code: string, supertext: ?string}>)|null $languages the language mapping (sendConfiguration only)
     */
    public function reporter(?string $siteUrl, ?string $apiBaseUrl = null, ?callable $languages = null): InstallationReporter
    {
        $version   = $this->pluginVersion ?? Settings::version();
        $version   = $version !== '' ? $version : 'unknown';
        $baseUrl   = $apiBaseUrl ?? $this->settings->baseUrl();
        $transport = $this->transport;
        $now       = $this->now;

        return new InstallationReporter(
            enabled: $siteUrl !== null && $this->enabled(),
            siteUrl: (string) $siteUrl,
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $version,
            systemName: self::SYSTEM_NAME,
            keyChecker: new CallableKeyChecker(
                static fn (#[\SensitiveParameter] string $key) => (new Client($key, baseUrl: $baseUrl, userAgent: self::PLUGIN_NAME . '/' . $version, transport: $transport, now: $now))->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $this->systemVersion ?? self::akeneoVersion(),
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $siteUrl !== null ? self::pingUrl($siteUrl) : null,
            reportUrl: $this->settings->reportUrl(),
            transport: $transport !== null ? new ReportTransport($transport) : null,
            now: $this->now,
            languages: $languages,
        );
    }

    /**
     * Runs one reporter action with the stored state and stores the result. Never throws.
     *
     * @param string      $action     one of the constants (onRequest, afterSettingsSaved, testConnection, authenticationFailed)
     * @param string|null $apiKey     default: the configured key (testConnection may pass the key typed in the form)
     * @param string|null $apiBaseUrl default: the configured API
     */
    public function run(string $action, ?string $siteUrl, #[\SensitiveParameter] ?string $apiKey = null, ?string $apiBaseUrl = null, int $httpStatus = 401): ?ReporterResult
    {
        try {
            $reporter = $this->reporter($siteUrl, $apiBaseUrl);
            $state    = ReporterState::from($this->settings->reporterState());
            $key      = $apiKey ?? $this->settings->apiKey();
            $result   = match ($action) {
                self::AFTER_SETTINGS_SAVED  => $reporter->afterSettingsSaved($state, $key),
                self::TEST_CONNECTION       => $reporter->testConnection($state, $key),
                self::AUTHENTICATION_FAILED => $reporter->authenticationFailed($state, $key, $httpStatus),
                default                     => $reporter->onRequest($state, $key),
            };
            $this->saveState($result->state);

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /** `supertext:check`: checks the key (like Test connection), then sends a `check` report now. */
    public function check(?string $siteUrl): ?ReporterResult
    {
        $tested = $this->run(self::TEST_CONNECTION, $siteUrl);

        try {
            $result = $this->reporter($siteUrl)->reportCheck($tested !== null ? $tested->state : ReporterState::from($this->settings->reporterState()));
            $this->saveState($result->state);

            return $result;
        } catch (\Throwable) {
            return $tested;
        }
    }

    /**
     * "Send configuration to Supertext" on System → Supertext: one `extensions` report with the installed
     * bundles and the language mapping, sent now (also within the hour after a failed report). Sends nothing
     * with reports off. Never throws.
     *
     * @param list<array{name: string, version?: ?string, active?: ?bool, type?: ?string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                                $languages
     */
    public function sendConfiguration(?string $siteUrl, array $extensions, array $languages): ?ReporterResult
    {
        try {
            $reporter = $this->reporter($siteUrl, null, static fn (): array => $languages);
            $result   = $reporter->sendExtensions(ReporterState::from($this->settings->reporterState()), $extensions);
            $this->saveState($result->state);

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Runs an action after the response was sent (kernel.terminate / console.terminate, see
     * ReportingSubscriber), so back-end requests never wait for Supertext or the center.
     */
    public function defer(string $action, ?string $siteUrl, int $httpStatus = 401): void
    {
        $this->deferred[] = function () use ($action, $siteUrl, $httpStatus): void {
            $this->run($action, $siteUrl, null, null, $httpStatus);
        };
    }

    /**
     * After a translation (dialog or command): defers authenticationFailed when Supertext rejected
     * the key (401/403) for one of the languages.
     *
     * @param array<string, array{key?: string, params?: array<string, string|int>}> $results EntityTranslator::translate()
     */
    public function deferAfterTranslation(?string $siteUrl, array $results): void
    {
        foreach ($results as $result) {
            if (($result['key'] ?? '') === 'auth_failed') {
                $this->defer(self::AUTHENTICATION_FAILED, $siteUrl, (int) ($result['params']['status'] ?? 401));

                return;
            }
        }
    }

    /** Runs the deferred actions (in order, each at most once). */
    public function runDeferred(): void
    {
        while ($task = array_shift($this->deferred)) {
            try {
                $task();
            } catch (\Throwable) {
            }
        }
    }

    public function hasDeferred(): bool
    {
        return $this->deferred !== [];
    }

    /** The latest stored key check (settings page), or null. */
    public function lastCheck(): ?KeyCheck
    {
        try {
            return ReporterState::from($this->settings->reporterState())->keyCheck;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{status: string, checked_at: string, http_status: int|null}|null for the settings page (never the fingerprint) */
    public static function checkToArray(?KeyCheck $check): ?array
    {
        return $check === null ? null : ['status' => $check->status, 'checked_at' => $check->checkedAt, 'http_status' => $check->httpStatus];
    }

    /** The English message for a key check (console). */
    public static function checkMessage(KeyCheck $check): string
    {
        return str_replace('{status}', (string) ($check->httpStatus ?? $check->errorCode ?? '?'), self::CHECK_MESSAGES[$check->status] ?? self::CHECK_MESSAGES[KeyCheck::ERROR]);
    }

    private function saveState(ReporterState $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (\is_string($json)) {
            $this->settings->saveReporterState($json);
        }
    }
}
