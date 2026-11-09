<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Settings;

use Supertext\AkeneoTranslationBundle\Api\SupertextClient;
use Supertext\AkeneoTranslationBundle\Api\SupertextException;
use Supertext\AkeneoTranslationBundle\Reporting\Reporting;
use Supertext\PluginHealth\InstallationReporter;
use Supertext\Sdk\V1\Languages;
use Supertext\Sdk\V1\SupertextException as SdkException;

/**
 * The bundle's settings: what an administrator saved under System → Supertext, with the
 * environment variables SUPERTEXT_API_KEY, SUPERTEXT_API_URL and SUPERTEXT_VERSION_REPORTS
 * taking precedence. SUPERTEXT_REVIEW_URL sets the review service for locales with "Translation review"
 * on (languages.<locale>.review; default SupertextClient::REVIEW_URL, set but empty = review off).
 *
 * Languages follow the Supertext SDK's rules: the locale's mapping (languages.<locale>.code, a drop-down
 * per locale on the settings page) wins, else a locale that equals a Supertext code apart from case and
 * "_"/"-" (de_CH → de-CH), else the SDK's default (es_ES → es, zh_TW → zh-Hant), else unsupported_language
 * and nothing is sent. One mapping serves both directions.
 */
final class Settings
{
    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public const PACKAGE = 'supertext/akeneo-supertext-translation';
    public const REPOSITORY_URL = 'https://github.com/Supertext/Akeneo-Supertext-Translation';

    public const ENVIRONMENTS = ['live', 'staging', 'testing', 'custom'];
    public const DEFAULT_TIMEOUT = 180;

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(private readonly SettingsStorage $repository)
    {
    }

    /** The installed version as Composer knows it (from the Git tag), e.g. "0.1.0" or "dev-main". */
    public static function version(): string
    {
        try {
            $version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled(self::PACKAGE)
                ? (string) \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE)
                : '';
        } catch (\Throwable) {
            $version = '';
        }

        return ltrim($version, 'v');
    }

    /** The GitHub release page of an X.Y.Z version, else "". */
    public static function releaseUrl(string $version): string
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) ? self::REPOSITORY_URL . '/releases/tag/v' . $version : '';
    }

    public function apiKey(): string
    {
        $fromEnvironment = self::environmentValue('SUPERTEXT_API_KEY');

        return SupertextClient::normalizeKey($fromEnvironment !== '' ? $fromEnvironment : (string) ($this->stored()['api_key'] ?? ''));
    }

    /** "environment", "settings" or "" (no key). */
    public function apiKeySource(): string
    {
        if (SupertextClient::normalizeKey(self::environmentValue('SUPERTEXT_API_KEY')) !== '') {
            return 'environment';
        }

        return $this->apiKey() !== '' ? 'settings' : '';
    }

    public function environment(): string
    {
        $value = (string) ($this->stored()['environment'] ?? 'live');

        return \in_array($value, self::ENVIRONMENTS, true) ? $value : 'live';
    }

    public function customUrl(): string
    {
        return trim((string) ($this->stored()['api_url'] ?? ''));
    }

    public function baseUrl(): string
    {
        $fromEnvironment = self::environmentValue('SUPERTEXT_API_URL');

        if ($fromEnvironment !== '') {
            return $fromEnvironment;
        }

        return SupertextClient::baseUrlFor($this->environment(), $this->environment() === 'custom' ? $this->customUrl() : '');
    }

    public function baseUrlFromEnvironment(): bool
    {
        return self::environmentValue('SUPERTEXT_API_URL') !== '';
    }

    /**
     * The review service for locales with "Translation review" on: SUPERTEXT_REVIEW_URL when it is set
     * (also when empty: review off for every locale), else Supertext's review service.
     */
    public function reviewUrl(): string
    {
        $value = $_SERVER['SUPERTEXT_REVIEW_URL'] ?? $_ENV['SUPERTEXT_REVIEW_URL'] ?? getenv('SUPERTEXT_REVIEW_URL');

        return \is_string($value) ? trim($value) : SupertextClient::REVIEW_URL;
    }

    /** Whether translations into $locale go through the review service ("Translation review", off by default). */
    public function review(string $locale): bool
    {
        return ($this->languages()[$locale]['review'] ?? false) === true;
    }

    public function timeout(): int
    {
        $value = (int) ($this->stored()['timeout'] ?? self::DEFAULT_TIMEOUT);

        return $value >= 30 && $value <= 1800 ? $value : self::DEFAULT_TIMEOUT;
    }

    /**
     * Supertext target code for an Akeneo locale: the mapping, else the exact match (de_CH → de-CH).
     *
     * @throws SupertextException unsupported_language (nothing may be sent)
     */
    public function languageCode(string $locale): string
    {
        try {
            return Languages::target($locale, $this->mapping());
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    /**
     * Supertext source code for an Akeneo locale: the mapping in its source form, else the bare language (en_US → en).
     *
     * @throws SupertextException unsupported_language
     */
    public function sourceCode(string $locale): string
    {
        try {
            return Languages::source($locale, $this->mapping());
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    /** The target code for a locale, or null when it has no mapping, no exact match and no default. */
    public function languageCodeOrNull(string $locale): ?string
    {
        try {
            return Languages::target($locale, $this->mapping());
        } catch (SdkException) {
            return null;
        }
    }

    /** The Supertext target a locale matches without a mapping (de_CH → de-CH, es_ES → null). */
    public static function exactLanguageCode(string $locale): ?string
    {
        return Languages::matchTarget($locale);
    }

    /**
     * What the settings page preselects for a locale nobody mapped: the exact match, else the SDK's
     * default (es_ES → es, en_IE → null).
     */
    public static function suggestedLanguageCode(string $locale): ?string
    {
        return Languages::suggestTarget($locale);
    }

    /**
     * Supertext's target languages for the drop-downs: code, name in the user's interface language
     * (PHP intl, else English), politeness; sorted by that name.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targetOptions(string $uiLocale = 'en_US'): array
    {
        $options = [];

        foreach (Languages::targetOptions() as $target) {
            $options[] = ['code' => $target['code'], 'name' => self::targetName($target['code'], $target['name'], $uiLocale), 'politeness' => $target['politeness']];
        }

        if (class_exists(\Collator::class)) {
            $collator = new \Collator($uiLocale);
            usort($options, static fn (array $a, array $b): int => (int) $collator->compare($a['name'], $b['name']));
        }

        return $options;
    }

    /** The target's name in $uiLocale (de: "Deutsch (Schweiz)"), else $english. */
    public static function targetName(string $code, string $english, string $uiLocale): string
    {
        if (!class_exists(\Locale::class)) {
            return $english;
        }

        $language = substr($uiLocale, 0, 2);

        // ICU names the Swiss German subdivisions "(sd=chzh)": name the city instead.
        if (preg_match('/^gsw-u-sd-(chzh|chbe)$/', $code, $match)) {
            $cities = [
                'chzh' => ['de' => 'Zürich', 'fr' => 'Zurich', 'it' => 'Zurigo', 'en' => 'Zurich'],
                'chbe' => ['de' => 'Bern', 'fr' => 'Berne', 'it' => 'Berna', 'en' => 'Bern'],
            ][$match[1]];
            $name = (string) \Locale::getDisplayLanguage('gsw', $uiLocale);

            return $name !== '' && $name !== 'gsw' ? $name . ' (' . ($cities[$language] ?? $cities['en']) . ')' : $english;
        }

        $name = (string) \Locale::getDisplayName($code, $uiLocale);

        return $name !== '' && strcasecmp($name, $code) !== 0 && !str_contains($name, '=') ? $name : $english;
    }

    /** @return array<string, string> Akeneo locale → Supertext code, as saved */
    public function mapping(): array
    {
        $mapping = [];

        foreach ($this->languages() as $locale => $language) {
            $code = trim((string) ($language['code'] ?? ''));

            if ($code !== '') {
                $mapping[(string) $locale] = $code;
            }
        }

        return $mapping;
    }

    /** "more" (formal), "less" (informal) or "" (Supertext's default; always "" where the target offers no choice). */
    public function politeness(string $locale): string
    {
        $value = (string) ($this->languages()[$locale]['politeness'] ?? '');
        $code  = $this->languageCodeOrNull($locale);

        if ($code !== null && Languages::supportsPoliteness($code) === false) {
            return '';
        }

        return \in_array($value, ['more', 'less'], true) ? $value : '';
    }

    /** @return array<string, array{code?: string, politeness?: string, review?: bool}> */
    public function languages(): array
    {
        $languages = $this->stored()['languages'] ?? [];

        return \is_array($languages) ? $languages : [];
    }

    /** The "Send version information to Supertext" setting as saved; null when never saved (on by default). */
    public function versionReports(): ?bool
    {
        $value = $this->stored()['version_reports'] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /** Whether SUPERTEXT_VERSION_REPORTS is set to a value that wins over the setting. */
    public function versionReportsFromEnvironment(): bool
    {
        return \in_array(strtolower(self::environmentValue('SUPERTEXT_VERSION_REPORTS')), ['0', 'false', 'off', 'no', '1', 'true', 'on', 'yes'], true);
    }

    /** Whether version reports are on: SUPERTEXT_VERSION_REPORTS, else the setting, else on. */
    public function versionReportsEnabled(): bool
    {
        $env = self::environmentValue('SUPERTEXT_VERSION_REPORTS');

        return InstallationReporter::reportsEnabled($this->versionReports(), $env !== '' ? $env : null);
    }

    /** SUPERTEXT_REPORT_URL (tests only), else null: the SDK's default. */
    public function reportUrl(): ?string
    {
        $url = self::environmentValue('SUPERTEXT_REPORT_URL');

        return $url !== '' ? $url : null;
    }

    /**
     * The site's base URL as Akeneo knows it: AKENEO_PIM_URL, else what the request was sent to
     * ($requestUrl, scheme, host and base path), else null. No trailing slash.
     */
    public static function siteUrl(?string $requestUrl = null): ?string
    {
        foreach ([self::environmentValue('AKENEO_PIM_URL'), (string) $requestUrl] as $url) {
            $url = rtrim(trim($url), '/');

            if (preg_match('#^https?://[^/\s]+#i', $url)) {
                return $url;
            }
        }

        return null;
    }

    public function client(): SupertextClient
    {
        // The User-Agent names the bundle and its version (as in the reports), so Supertext and the review
        // service see which release calls. forReview() gives the client for locales with Translation review.
        return new SupertextClient(
            $this->apiKey(),
            $this->baseUrl(),
            $this->timeout(),
            Reporting::PLUGIN_NAME . '/' . (self::version() !== '' ? self::version() : 'dev'),
            reviewUrl: $this->reviewUrl(),
        );
    }

    /**
     * Saves what the settings page sent. An empty api_key keeps the saved key; clear_api_key removes it.
     *
     * @param array<string, mixed> $input
     *
     * @return list<array{key: string, params: array<string, string>, message: string}> validation errors
     *         (English message, plus the UI key `supertext_translation.error.<key>`; nothing is saved when there are any)
     */
    public function update(array $input): array
    {
        $values = $this->stored();
        $errors = [];

        if (($input['clear_api_key'] ?? false) === true) {
            unset($values['api_key']);
        } elseif (\is_string($input['api_key'] ?? null) && trim($input['api_key']) !== '') {
            $values['api_key'] = SupertextClient::normalizeKey($input['api_key']);
        }

        if (\array_key_exists('environment', $input)) {
            if (!\in_array($input['environment'], self::ENVIRONMENTS, true)) {
                $errors[] = ['key' => 'unknown_environment', 'params' => [], 'message' => 'Unknown API environment.'];
            } else {
                $values['environment'] = $input['environment'];
            }
        }

        if (\array_key_exists('api_url', $input)) {
            $url = trim((string) $input['api_url']);

            if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
                $errors[] = ['key' => 'invalid_url', 'params' => [], 'message' => 'The API address must start with https://.'];
            }

            $values['api_url'] = $url;
        }

        if (($values['environment'] ?? 'live') === 'custom' && trim((string) ($values['api_url'] ?? '')) === '') {
            $errors[] = ['key' => 'missing_url', 'params' => [], 'message' => 'Enter the API address for a custom environment.'];
        }

        if (\array_key_exists('timeout', $input)) {
            $timeout = (int) $input['timeout'];

            if ($timeout < 30 || $timeout > 1800) {
                $errors[] = ['key' => 'invalid_timeout', 'params' => [], 'message' => 'The timeout must be between 30 and 1800 seconds.'];
            } else {
                $values['timeout'] = $timeout;
            }
        }

        if (\array_key_exists('version_reports', $input)) {
            $values['version_reports'] = (bool) $input['version_reports'];
        }

        if (\is_array($input['languages'] ?? null)) {
            $languages = [];

            foreach ($input['languages'] as $locale => $language) {
                if (!\is_string($locale) || !preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $locale) || !\is_array($language)) {
                    continue;
                }

                $code       = trim((string) ($language['code'] ?? ''));
                $politeness = (string) ($language['politeness'] ?? '');
                $previous   = (string) ($this->languages()[$locale]['code'] ?? '');

                // One of Supertext's codes (stored in its spelling). A code saved by an earlier version that
                // isn't in the list is kept as it is, so nothing configured is lost.
                $known = $code !== '' ? Languages::matchTarget($code) : null;

                if ($code !== '' && $known === null && $code !== $previous) {
                    $errors[] = ['key' => 'invalid_code', 'params' => ['code' => $code, 'locale' => $locale], 'message' => sprintf('"%s" is not one of Supertext\'s languages (%s).', $code, $locale)];

                    continue;
                }

                $entry = [];

                // The chosen code is stored as chosen, also when it is the preselected suggestion.
                if ($code !== '') {
                    $entry['code'] = $known ?? $code;
                }

                // Formal/informal only where the target offers it (Supertext rejects it elsewhere).
                $target = $code !== '' ? ($known ?? $code) : self::suggestedLanguageCode($locale);
                if (\in_array($politeness, ['more', 'less'], true) && ($target === null || Languages::supportsPoliteness($target) !== false)) {
                    $entry['politeness'] = $politeness;
                }

                // Translation review: on or off per target locale (off when not sent).
                if (($language['review'] ?? false) === true) {
                    $entry['review'] = true;
                }

                if ($entry !== []) {
                    $languages[$locale] = $entry;
                }
            }

            $values['languages'] = $languages;
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->repository->save($values);
        $this->stored = $values;

        return [];
    }

    /** The reporter's stored state as JSON, or null (never throws). */
    public function reporterState(): ?string
    {
        try {
            return $this->repository->loadReporterState();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Stores the reporter's state (never throws: reporting must not break a request). */
    public function saveReporterState(string $json): void
    {
        try {
            $this->repository->saveReporterState($json);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->stored ??= $this->repository->load();
    }

    private static function environmentValue(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? trim($value) : '';
    }
}
