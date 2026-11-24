<?php

declare(strict_types=1);

namespace Supertext\AkeneoTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\AkeneoTranslationBundle\Reporting\InstalledExtensions;
use Supertext\AkeneoTranslationBundle\Reporting\Reporting;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Tests\Support\MemoryStorage;
use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\Supertext;

/**
 * The bundle's glue to Supertext Plugin Health's InstallationReporter (with the SDK's key check): when each action runs, what is stored,
 * the ping answers. A fake transport stands in for Supertext and the Commando Center.
 */
final class ReportingTest extends TestCase
{
    private const SITE = 'https://pim.example.ch';
    private const REPORT_URL = 'http://center.test/api/v1/report';

    /** @var \ArrayObject<int, HttpRequest> */
    private \ArrayObject $requests;
    private int $keyStatus = 200;
    private int $centerStatus = 202;
    private float $time = 1_791_000_000.0;

    protected function setUp(): void
    {
        $this->requests = new \ArrayObject();
        $_SERVER['SUPERTEXT_REPORT_URL'] = self::REPORT_URL;
    }

    protected function tearDown(): void
    {
        foreach (['SUPERTEXT_REPORT_URL', 'SUPERTEXT_VERSION_REPORTS', 'SUPERTEXT_API_KEY'] as $name) {
            unset($_SERVER[$name]);
        }
    }

    private function reporting(MemoryStorage $storage, string $version = '1.0.0', string $akeneo = '2026.4.0'): Reporting
    {
        $test      = $this;
        $transport = new class ($test) implements HttpTransport {
            public function __construct(private ReportingTest $test)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                return $this->test->answer($request);
            }
        };

        return new Reporting(new Settings($storage), $transport, $akeneo, $version, fn (): float => $this->time);
    }

    /** @internal the fake transport's answers */
    public function answer(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($request->url === self::REPORT_URL) {
            return new HttpResponse($this->centerStatus, '{"ok":true}');
        }

        self::assertStringEndsWith('/features', $request->url);

        return new HttpResponse($this->keyStatus, $this->keyStatus === 200 ? '[]' : '{"error_code":"UNAUTHORIZED"}');
    }

    /** @return list<array<string, mixed>> the reports sent to the center */
    private function reports(): array
    {
        $reports = [];

        foreach ($this->requests as $request) {
            if ($request->url === self::REPORT_URL) {
                $reports[] = json_decode((string) $request->body, true, 16, JSON_THROW_ON_ERROR);
            }
        }

        return $reports;
    }

    private function keyChecks(): int
    {
        return \count(array_filter($this->requests->getArrayCopy(), static fn (HttpRequest $r): bool => str_ends_with($r->url, '/features')));
    }

    public function testSettingsSavedChecksTheKeyAndReportsInstalledThenSettingsSaved(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'secret-key-1234']);
        $reporting = $this->reporting($storage);

        $first = $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        self::assertNotNull($first);
        self::assertSame('ok', $first->check?->status);
        self::assertSame('installed', $first->event);
        self::assertTrue($first->sent);

        $report = $this->reports()[0];
        self::assertSame('Akeneo-Supertext-Translation', $report['plugin']['name']);
        self::assertSame('1.0.0', $report['plugin']['version']);
        self::assertSame(['name' => 'Akeneo PIM', 'version' => '2026.4.0'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(self::SITE, $report['site_url']);
        self::assertSame(['url' => self::SITE . '/supertext/ping'], $report['callback']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version']);
        self::assertStringContainsString('Akeneo-Supertext-Translation/1.0.0', $this->requests[0]->headers['User-Agent'] ?? '', 'the key check is the SDK client\'s');

        $second = $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        self::assertSame('settings_saved', $second?->event);

        // Neither the key nor its fingerprint ever leaves: the state stays local and has no key.
        foreach ($this->requests as $request) {
            self::assertStringNotContainsString('secret-key-1234', (string) $request->body);
        }
        self::assertStringNotContainsString('secret-key-1234', (string) $storage->reporterState);
        self::assertSame('ok', $reporting->lastCheck()?->status);
    }

    public function testStateStoredByTheSdk02ReporterIsKept(): void
    {
        // What the Supertext SDK 0.2 reporter stored before the switch to Plugin Health.
        $fingerprint = hash('sha256', 'key-a');
        $storage     = new MemoryStorage(['api_key' => 'key-a'], '{"reportedVersions":"1.0.0|2026.4.0|' . PHP_VERSION . '","reportedKeyStatus":"ok","lastFailureAt":null,'
            . '"keyCheck":{"status":"ok","checkedAt":"2026-10-13T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . $fingerprint . '"}}');
        $reporting   = $this->reporting($storage);

        self::assertSame('2026-10-13T08:00:00Z', $reporting->lastCheck()?->checkedAt);
        self::assertNull($reporting->run(Reporting::ON_REQUEST, self::SITE)?->event, 'no new installed report after the update');
        self::assertCount(0, $this->requests);

        self::assertSame('settings_saved', $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE)?->event);
        self::assertSame(
            // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key).
            ['reportedVersions', 'reportedKeyStatus', 'lastFailureAt', 'keyCheck', 'keyChecks', 'reportedKeyStatuses'],
            array_keys(json_decode((string) $storage->reporterState, true, 16, JSON_THROW_ON_ERROR)),
            'same stored format',
        );
    }

    public function testOnRequestReportsOnlyWhatChanged(): void
    {
        $storage = new MemoryStorage(['api_key' => 'key-a']);
        $this->reporting($storage)->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        $this->requests->exchangeArray([]);

        // Nothing changed: no key check, no report.
        $result = $this->reporting($storage)->run(Reporting::ON_REQUEST, self::SITE);
        self::assertNull($result?->event);
        self::assertCount(0, $this->requests);

        // A new bundle version: "upgraded" (the key is unchanged, so no check).
        $result = $this->reporting($storage, '1.1.0')->run(Reporting::ON_REQUEST, self::SITE);
        self::assertSame('upgraded', $result?->event);
        self::assertSame(0, $this->keyChecks());

        // Another key (e.g. SUPERTEXT_API_KEY changed): checked; a changed result is reported.
        $_SERVER['SUPERTEXT_API_KEY'] = 'key-b';
        $this->keyStatus              = 401;
        $result                       = $this->reporting($storage, '1.1.0')->run(Reporting::ON_REQUEST, self::SITE);
        self::assertSame(1, $this->keyChecks());
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame('api_key_check', $result?->event);
    }

    public function testAuthenticationFailedMarksTheKeyInvalidOnce(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'key-a']);
        $reporting = $this->reporting($storage);
        $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);

        $reporting->deferAfterTranslation(self::SITE, [
            'de_CH' => ['key' => 'auth_failed', 'params' => ['status' => 403]],
            'fr_CH' => ['key' => 'auth_failed', 'params' => ['status' => 403]],
        ]);
        self::assertTrue($reporting->hasDeferred());
        self::assertCount(1, $this->reports(), 'deferred actions wait for runDeferred()');

        $checks = $this->keyChecks();
        $reporting->runDeferred();
        self::assertFalse($reporting->hasDeferred());
        self::assertSame($checks, $this->keyChecks(), 'no request to Supertext for a rejected translation');
        self::assertSame('invalid', $reporting->lastCheck()?->status);
        self::assertSame(403, $reporting->lastCheck()?->httpStatus);
        self::assertSame(['installed', 'api_key_check'], array_column($this->reports(), 'event'));

        // The same failure again: nothing new to report.
        $reporting->deferAfterTranslation(self::SITE, ['de_CH' => ['key' => 'auth_failed', 'params' => ['status' => 403]]]);
        $reporting->runDeferred();
        self::assertCount(2, $this->reports());

        // Other errors don't touch the key check.
        $reporting->deferAfterTranslation(self::SITE, ['de_CH' => ['key' => 'unsupported_language', 'params' => []]]);
        self::assertFalse($reporting->hasDeferred());
    }

    public function testTestConnectionUsesTheGivenKeyAndApi(): void
    {
        $storage   = new MemoryStorage();
        $reporting = $this->reporting($storage);

        $result = $reporting->run(Reporting::TEST_CONNECTION, self::SITE, 'typed-key', 'https://api.staging.test/v1/');
        self::assertSame('ok', $result?->check?->status);
        self::assertSame('https://api.staging.test/v1/features', $this->requests[0]->url);
        self::assertSame('Supertext-Auth-Key typed-key', $this->requests[0]->headers['Authorization']);

        $missing = $reporting->run(Reporting::TEST_CONNECTION, self::SITE, '');
        self::assertSame('missing', $missing?->check?->status);
    }

    public function testOffMeansNoReportButTheKeyIsStillChecked(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'key-a', 'version_reports' => false]);
        $reporting = $this->reporting($storage);

        $result = $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reports());
        self::assertSame(1, $this->keyChecks());

        // The environment wins over the setting.
        $_SERVER['SUPERTEXT_VERSION_REPORTS'] = 'yes';
        $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        self::assertCount(1, $this->reports());

        // Without a site URL (a command without AKENEO_PIM_URL): checked, not reported.
        $this->requests->exchangeArray([]);
        $reporting->run(Reporting::TEST_CONNECTION, null);
        self::assertSame(1, $this->keyChecks());
        self::assertSame([], $this->reports());
    }

    public function testAnUnreachableCenterIsTriedAgainAfterAnHour(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'key-a']);
        $reporting = $this->reporting($storage);

        $this->centerStatus = 503;
        $result             = $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        self::assertFalse($result?->sent);

        $this->centerStatus = 202;
        self::assertNull($reporting->run(Reporting::ON_REQUEST, self::SITE)?->event);
        $this->time += 3601;
        self::assertSame('installed', $reporting->run(Reporting::ON_REQUEST, self::SITE)?->event);
    }

    public function testSendConfigurationSendsBundlesAndTheLanguageMappingNow(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'key-a']);
        $reporting = $this->reporting($storage);

        // Within the hour after a failed report: still sent, the person asked for it.
        $this->centerStatus = 503;
        $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE);
        $this->centerStatus = 202;
        $this->requests->exchangeArray([]);

        $result = $reporting->sendConfiguration(
            self::SITE,
            [['name' => 'acme/akeneo-connector', 'version' => '2.1.0', 'active' => true, 'type' => 'bundle']],
            [['code' => 'en_US', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'en_IE', 'supertext' => null]],
        );

        self::assertSame('extensions', $result?->event);
        self::assertTrue($result->sent);
        [$report] = $this->reports();
        self::assertSame('extensions', $report['event']);
        self::assertSame([['name' => 'acme/akeneo-connector', 'version' => '2.1.0', 'active' => true, 'type' => 'bundle']], $report['extensions']);
        self::assertSame([['code' => 'en_US', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'en_IE', 'supertext' => null]], $report['languages']);
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        $reporting = $this->reporting(new MemoryStorage(['api_key' => 'key-a', 'version_reports' => false]));

        self::assertFalse((bool) $reporting->sendConfiguration(self::SITE, [['name' => 'acme/x']], [['code' => 'de_CH', 'supertext' => 'de-CH']])?->sent);
        self::assertSame([], $this->reports());
    }

    public function testInstalledExtensionsLeaveOutAkeneoAndTheFramework(): void
    {
        $bundles = [
            'FrameworkBundle'            => 'Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle',
            'PimEnrichBundle'            => 'Akeneo\\Pim\\Enrichment\\Bundle\\AkeneoPimEnrichmentBundle',
            'OroSecurityBundle'          => 'Oro\\Bundle\\SecurityBundle\\OroSecurityBundle',
            'SupertextTranslationBundle' => 'Supertext\\AkeneoTranslationBundle\\SupertextTranslationBundle',
            'AcmeBundle'                 => 'Acme\\Bundle\\AcmeBundle',
        ];
        $packages = ['Supertext\\AkeneoTranslationBundle\\SupertextTranslationBundle' => ['name' => 'supertext/akeneo-supertext-translation', 'version' => '1.2.0']];

        self::assertSame([
            ['name' => 'AcmeBundle', 'version' => null, 'active' => true, 'type' => 'bundle'],
            ['name' => 'supertext/akeneo-supertext-translation', 'version' => '1.2.0', 'active' => true, 'type' => 'bundle'],
        ], InstalledExtensions::from($bundles, static fn (string $class): ?array => $packages[$class] ?? null));
    }

    public function testCheckCommandSendsACheckReport(): void
    {
        $storage   = new MemoryStorage(['api_key' => 'key-a']);
        $reporting = $this->reporting($storage);

        $result = $reporting->check(self::SITE);
        self::assertSame('check', $result?->event);
        self::assertTrue($result?->sent);
        self::assertSame('ok', $result?->check?->status);
        // The first report of an installation is "installed" (from the key check), then the check report.
        self::assertSame(['installed', 'check'], array_column($this->reports(), 'event'));
        self::assertSame('API key checked: the connection to Supertext works.', Reporting::checkMessage($result->check));
    }

    public function testStorageFailuresNeverBreakTheRequest(): void
    {
        $reporting = $this->reporting(new MemoryStorage(['api_key' => 'key-a'], null, true));

        self::assertSame('installed', $reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::SITE)?->event);
        self::assertNull($reporting->lastCheck());
    }

    public function testPing(): void
    {
        $reporting = $this->reporting(new MemoryStorage());

        $ok = $reporting->ping('abcdefghijklmnop_1234');
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"Akeneo-Supertext-Translation","challenge":"abcdefghijklmnop_1234"}', $ok->body);

        self::assertSame(400, $reporting->ping('<script>')->status);
        self::assertSame(400, $reporting->ping(null)->status);

        $off = $this->reporting(new MemoryStorage(['version_reports' => false]));
        self::assertSame(404, $off->ping('abcdefghijklmnop_1234')->status);
        self::assertSame(self::SITE . '/supertext/ping', Reporting::pingUrl(self::SITE . '/'));
    }
}
