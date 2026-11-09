<?php

declare(strict_types=1);

namespace Supertext\AkeneoTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\AkeneoTranslationBundle\Api\SupertextClient;
use Supertext\AkeneoTranslationBundle\Api\SupertextException;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Tests\Support\MemoryStorage;

/** Language mapping (mapping, exact match, SDK default), the version reports setting and the site URL. */
final class SettingsTest extends TestCase
{
    private const ENV = ['SUPERTEXT_VERSION_REPORTS', 'AKENEO_PIM_URL', 'SUPERTEXT_API_KEY', 'SUPERTEXT_REVIEW_URL'];

    protected function tearDown(): void
    {
        foreach (self::ENV as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    public function testLocalesThatEqualASupertextCodeNeedNoMapping(): void
    {
        $settings = new Settings(new MemoryStorage());

        self::assertSame('de-CH', $settings->languageCode('de_CH'));
        self::assertSame('en-US', $settings->languageCode('en_US'));
        self::assertSame('en', $settings->sourceCode('en_US'));
        self::assertSame('es', $settings->sourceCode('es_ES'));
        self::assertSame('de-CH', Settings::exactLanguageCode('de_CH'));
        self::assertNull(Settings::exactLanguageCode('es_ES'));
    }

    public function testUnmappedLocalesUseTheSdkDefault(): void
    {
        $settings = new Settings(new MemoryStorage());

        foreach (['de_DE' => 'de-DE', 'es_ES' => 'es', 'nl_NL' => 'nl', 'sl_SI' => 'sl', 'zh_CN' => 'zh-Hans', 'zh_TW' => 'zh-Hant', 'sr_Latn_RS' => 'sr-Latn'] as $locale => $code) {
            self::assertSame($code, $settings->languageCode($locale), $locale);
            self::assertSame($code, Settings::suggestedLanguageCode($locale), $locale);
        }

        self::assertSame('de-CH', Settings::suggestedLanguageCode('de_CH'));
    }

    public function testNoGuessingWithoutADefault(): void
    {
        $settings = new Settings(new MemoryStorage());

        foreach (['en_IE', 'de_LI', 'fr_BE', 'sr_RS'] as $locale) {
            self::assertNull($settings->languageCodeOrNull($locale), $locale);
            self::assertNull(Settings::suggestedLanguageCode($locale), $locale);

            try {
                $settings->languageCode($locale);
                self::fail("No exception for $locale");
            } catch (SupertextException $e) {
                self::assertSame('unsupported_language', $e->key);
                self::assertSame($locale, $e->params['language']);
            }
        }
    }

    public function testTheMappingWinsForTargetAndSource(): void
    {
        $settings = new Settings(new MemoryStorage(['languages' => [
            'es_ES' => ['code' => 'es'],
            'de_DE' => ['code' => 'de-CH', 'politeness' => 'less'],
            'en_US' => ['code' => 'en-GB', 'politeness' => 'more'],
            'zh_CN' => ['code' => 'zh-Hant'],
            'en_IE' => ['code' => 'en-GB'],
        ]]));

        self::assertSame('es', $settings->languageCode('es_ES'));
        self::assertSame('de-CH', $settings->languageCode('de_DE'));
        self::assertSame('zh-Hant', $settings->languageCode('zh_CN'));
        self::assertSame('en-GB', $settings->languageCode('en_IE'));
        // One mapping for both directions: a mapped target is sent as source in its source form.
        self::assertSame('de', $settings->sourceCode('de_DE'));
        self::assertSame('en', $settings->sourceCode('en_US'));
        self::assertSame('zh', $settings->sourceCode('zh_CN'));
        self::assertSame('less', $settings->politeness('de_DE'));
        // A politeness the target doesn't offer is never sent (Supertext would reject it).
        self::assertSame('', $settings->politeness('en_US'));
    }

    public function testSavingTheMapping(): void
    {
        $storage  = new MemoryStorage(['languages' => ['xx_YY' => ['code' => 'xx-YY']]]);
        $settings = new Settings($storage);

        self::assertSame([], $settings->update(['languages' => [
            'de_CH' => ['code' => 'de-CH', 'politeness' => 'more'], // the preselected match: stored as chosen
            'es_ES' => ['code' => 'ES'],                            // normalized to Supertext's spelling
            'fr_FR' => ['code' => 'fr_ch'],
            'en_IE' => ['code' => ''],                              // no default, not chosen
            'en_US' => ['code' => 'en-US', 'politeness' => 'more'], // en-US offers no politeness: dropped
            'xx_YY' => ['code' => 'xx-YY'],                         // saved by an earlier version: kept
        ]]));

        self::assertSame([
            'de_CH' => ['code' => 'de-CH', 'politeness' => 'more'],
            'es_ES' => ['code' => 'es'],
            'fr_FR' => ['code' => 'fr-CH'],
            'en_US' => ['code' => 'en-US'],
            'xx_YY' => ['code' => 'xx-YY'],
        ], $storage->values['languages']);
        self::assertSame(['de_CH' => 'de-CH', 'es_ES' => 'es', 'fr_FR' => 'fr-CH', 'en_US' => 'en-US', 'xx_YY' => 'xx-YY'], $settings->mapping());
        self::assertNull($settings->languageCodeOrNull('en_IE'));

        // Only codes from Supertext's list (or the one already saved for that locale).
        foreach (['not a code!', 'xx-YY', 'es-ES'] as $code) {
            $errors = $settings->update(['languages' => ['de_CH' => ['code' => $code]]]);
            self::assertSame('invalid_code', $errors[0]['key'], $code);
            self::assertSame(['code' => $code, 'locale' => 'de_CH'], $errors[0]['params']);
        }
    }

    public function testTranslationReviewPerLocale(): void
    {
        $storage  = new MemoryStorage();
        $settings = new Settings($storage);

        self::assertFalse($settings->review('de_CH'), 'off by default');
        self::assertSame([], $settings->update(['languages' => [
            'de_CH' => ['code' => 'de-CH', 'review' => true],
            'fr_FR' => ['code' => 'fr-FR', 'review' => false],
            'it_IT' => ['review' => true],                    // the preselected suggestion, review on
        ]]));

        self::assertSame([
            'de_CH' => ['code' => 'de-CH', 'review' => true],
            'fr_FR' => ['code' => 'fr-FR'],
            'it_IT' => ['review' => true],
        ], $storage->values['languages']);
        self::assertTrue($settings->review('de_CH'));
        self::assertFalse($settings->review('fr_FR'));
        self::assertTrue($settings->review('it_IT'));
        self::assertSame('it-IT', $settings->languageCode('it_IT'));
    }

    public function testReviewUrlDefaultsToSupertextsReviewService(): void
    {
        $settings = new Settings(new MemoryStorage());

        self::assertSame(SupertextClient::REVIEW_URL, $settings->reviewUrl());
        self::assertSame('https://localizationreview-production.up.railway.app/v1/', SupertextClient::REVIEW_URL);
        self::assertNotSame($settings->client(), $settings->client()->forReview(true));

        $_SERVER['SUPERTEXT_REVIEW_URL'] = 'http://localhost:3000/v1/';
        self::assertSame('http://localhost:3000/v1/', $settings->reviewUrl());

        // Set but empty: Translation review off for every locale.
        $_SERVER['SUPERTEXT_REVIEW_URL'] = '';
        self::assertSame('', $settings->reviewUrl());
        $client = $settings->client();
        self::assertSame($client, $client->forReview(true));
    }

    public function testTargetOptionsAreLocalized(): void
    {
        $options = Settings::targetOptions('de_DE');
        $byCode  = array_column($options, 'name', 'code');

        self::assertCount(37, $options);
        self::assertSame('Deutsch (Schweiz)', $byCode['de-CH']);
        self::assertSame('Schweizerdeutsch (Zürich)', $byCode['gsw-u-sd-chzh']);
        self::assertSame('English (United States)', array_column(Settings::targetOptions('en_US'), 'name', 'code')['en-US']);
        self::assertSame('suisse allemand (Berne)', array_column(Settings::targetOptions('fr_FR'), 'name', 'code')['gsw-u-sd-chbe']);
    }

    public function testVersionReportsAreOnByDefaultAndTheEnvironmentWins(): void
    {
        $storage  = new MemoryStorage();
        $settings = new Settings($storage);

        self::assertNull($settings->versionReports());
        self::assertTrue($settings->versionReportsEnabled());
        self::assertFalse($settings->versionReportsFromEnvironment());

        self::assertSame([], $settings->update(['version_reports' => false]));
        self::assertFalse($storage->values['version_reports']);
        self::assertFalse($settings->versionReportsEnabled());

        $_SERVER['SUPERTEXT_VERSION_REPORTS'] = 'on';
        self::assertTrue($settings->versionReportsFromEnvironment());
        self::assertTrue($settings->versionReportsEnabled());

        $settings->update(['version_reports' => true]);
        $_SERVER['SUPERTEXT_VERSION_REPORTS'] = '0';
        self::assertFalse($settings->versionReportsEnabled());
    }

    public function testSiteUrl(): void
    {
        self::assertSame('https://pim.example.ch', Settings::siteUrl('https://pim.example.ch/'));
        self::assertNull(Settings::siteUrl(null));
        self::assertNull(Settings::siteUrl('pim.example.ch'));

        $_SERVER['AKENEO_PIM_URL'] = 'https://akeneo.example.com/pim/';
        self::assertSame('https://akeneo.example.com/pim', Settings::siteUrl('http://10.0.0.5:8080'));
    }
}
