<?php

declare(strict_types=1);

namespace Supertext\AkeneoTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Tests\Support\MemoryStorage;
use Supertext\AkeneoTranslationBundle\Translation\TranslationFileError;
use Supertext\AkeneoTranslationBundle\Translation\TranslationFileGlue;
use Supertext\AkeneoTranslationBundle\Translation\TranslationFileStore;
use Supertext\Sdk\Files\FileJob;
use Supertext\Sdk\Files\FileSegment;
use Supertext\Sdk\Files\TranslationFiles;

/** Products, locales and values in memory; records writes. */
final class FakeFileStore implements TranslationFileStore
{
    /** @var array<string, string> "type:id" => label */
    public array $labels = [];
    /** @var array<string, list<FileSegment>> "type:id" => segments */
    public array $segments = [];
    /** @var list<string> */
    public array $locales = ['en_US', 'de_CH', 'fr_FR', 'en_IE'];
    /** @var array<string, bool> */
    public array $denied = [];
    /** @var list<array{string, string, string, string, array<string, string>, bool}> */
    public array $written = [];
    /** Texts the write-back keeps because the value has text (overwrite off). */
    public int $keep = 0;

    public function label(string $type, string $id): ?string
    {
        return $this->labels["$type:$id"] ?? null;
    }

    public function canEdit(string $type): bool
    {
        return empty($this->denied[$type]);
    }

    public function locales(): array
    {
        return $this->locales;
    }

    public function segments(string $type, string $id, string $source, string $target): array
    {
        return $this->segments["$type:$id"] ?? [];
    }

    public function write(string $type, string $id, string $source, string $target, array $texts, bool $overwrite): array
    {
        $this->written[] = [$type, $id, $source, $target, $texts, $overwrite];
        $kept            = $overwrite ? 0 : min($this->keep, \count($texts));

        return ['status' => 'translated', 'translated' => \count($texts) - $kept, 'existing' => $kept, 'message' => '', 'key' => '', 'params' => [], 'detail' => ''];
    }
}

final class FileExportImportTest extends TestCase
{
    private const UUID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    private FakeFileStore $store;
    private TranslationFileGlue $glue;

    protected function setUp(): void
    {
        $this->store                             = new FakeFileStore();
        $this->store->labels['product:' . self::UUID] = 'chair-1';
        $this->store->segments['product:' . self::UUID] = [
            new FileSegment('description|ecommerce', '<p>Fresh <b>bread</b> every day.</p>', true),
            new FileSegment('name|', 'Chair'),
        ];
        // de_CH mapped to de-CH by an exact match, fr_FR by the default, en_IE has no Supertext language.
        $this->glue = new TranslationFileGlue($this->store, new Settings(new MemoryStorage(['languages' => ['fr_FR' => ['code' => 'fr-CH']]])));
    }

    private static function code(callable $fn): string
    {
        try {
            $fn();

            return 'no error';
        } catch (TranslationFileError $e) {
            return $e->key;
        }
    }

    /** Fills <target> like a translation tool. @param array<string, string> $targets */
    private static function translate(string $xliff, array $targets): string
    {
        foreach ($targets as $key => $target) {
            $xliff = (string) preg_replace('#(<trans-unit id="' . preg_quote(htmlspecialchars($key, ENT_QUOTES | ENT_XML1), '#') . '"[^>]*>\s*<source>.*?</source>)#s', '$1<target>' . $target . '</target>', $xliff, 1);
        }

        return $xliff;
    }

    public function testReferenceRoundTrip(): void
    {
        $reference = TranslationFileGlue::reference('product', self::UUID, 'en_US', 'de_CH');
        self::assertSame('product:' . self::UUID . ':en_US:de_CH', $reference);
        self::assertSame(['type' => 'product', 'id' => self::UUID, 'source' => 'en_US', 'target' => 'de_CH'], TranslationFileGlue::parseReference($reference));
        self::assertSame(['type' => 'product_model', 'id' => '12', 'source' => 'en_US', 'target' => 'fr_FR'], TranslationFileGlue::parseReference('product_model:12:en_US:fr_FR'));

        foreach (['product:12:en_US', 'page:12:en_US:de_CH', 'product:x y:en_US:de_CH', 'product:12:en_US:de_CH:x', ''] as $bad) {
            self::assertNull(TranslationFileGlue::parseReference($bad), $bad);
        }

        self::assertSame('akeneo-12-de-CH.xlf', TranslationFileGlue::fileName('12', ['de-CH'], 'xliff'));
        self::assertSame('akeneo-12.xlf', TranslationFileGlue::fileName('12', ['de-CH', 'fr-CH'], 'xliff'));
        self::assertSame('akeneo-12-de-CH.html', TranslationFileGlue::fileName('12', ['de-CH'], 'html'));
    }

    public function testExportUsesTheTranslatorsKeysAndTheMapping(): void
    {
        $file = $this->glue->export('product', self::UUID, 'en_US', ['de_CH', 'fr_FR', 'en_IE'], 'xliff');
        self::assertSame('akeneo-' . self::UUID . '.xlf', $file['name']);
        self::assertSame('application/x-xliff+xml', $file['type']);

        $jobs = TranslationFiles::read($file['content']);
        self::assertSame([
            ['Akeneo-Supertext-Translation', 'product:' . self::UUID . ':en_US:de_CH', 'en', 'de-CH'],
            ['Akeneo-Supertext-Translation', 'product:' . self::UUID . ':en_US:fr_FR', 'en', 'fr-CH'],
            ['Akeneo-Supertext-Translation', 'product:' . self::UUID . ':en_US:en_IE', 'en', 'en-IE'],
        ], array_map(static fn ($j): array => [$j->plugin, $j->reference, $j->sourceLanguage, $j->targetLanguage], $jobs));
        self::assertSame(['description|ecommerce', 'name|'], array_map(static fn ($u): string => $u->key, $jobs[0]->units));

        $html = $this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'html');
        self::assertSame(['akeneo-' . self::UUID . '-de-CH.html', 'text/html; charset=utf-8'], [$html['name'], $html['type']]);
        self::assertSame([], $this->store->written);
    }

    public function testExportRefusals(): void
    {
        self::assertSame('file_html_one', self::code(fn () => $this->glue->export('product', self::UUID, 'en_US', ['de_CH', 'fr_FR'], 'html')));
        self::assertSame('choose_target', self::code(fn () => $this->glue->export('product', self::UUID, 'en_US', ['en_US', 'xx_XX'], 'xliff')));
        self::assertSame('choose_source', self::code(fn () => $this->glue->export('product', self::UUID, 'xx_XX', ['de_CH'], 'xliff')));
        self::assertSame('file_not_found', self::code(fn () => $this->glue->export('product_model', '7', 'en_US', ['de_CH'], 'xliff')));
        $this->store->denied['product'] = true;
        self::assertSame('forbidden_product', self::code(fn () => $this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'xliff')));
        $this->store->labels['product_model:7'] = 'model';
        self::assertSame('file_nothing', self::code(fn () => $this->glue->export('product_model', '7', 'en_US', ['de_CH'], 'xliff')));
    }

    public function testImportWritesOkAndCountsTheRest(): void
    {
        $xliff = $this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'xliff')['content'];
        $done  = self::translate($xliff, [
            'description|ecommerce' => '<bpt id="1">&lt;p&gt;</bpt>Frisches <bpt id="2">&lt;b&gt;</bpt>Brot<ept id="2">&lt;/b&gt;</ept> jeden Tag.<ept id="1">&lt;/p&gt;</ept>',
        ]);
        $this->store->segments['product:' . self::UUID][] = new FileSegment('short|', 'Added later');

        $jobs = $this->glue->import($done, false, false);
        self::assertCount(1, $jobs);
        self::assertNull($jobs[0]['error']);
        self::assertSame(['chair-1', 'de_CH', 1, 0, 1, 0, 1, 0], [$jobs[0]['label'], $jobs[0]['locale'], $jobs[0]['imported'], $jobs[0]['source_changed'], $jobs[0]['untranslated'], $jobs[0]['invalid'], $jobs[0]['missing'], $jobs[0]['existing']]);
        self::assertSame([['product', self::UUID, 'en_US', 'de_CH', ['description|ecommerce' => '<p>Frisches <b>Brot</b> jeden Tag.</p>'], false]], $this->store->written);
    }

    public function testSourceChangedIsWrittenOnlyWithTheOption(): void
    {
        $done = self::translate($this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'xliff')['content'], ['name|' => 'Stuhl']);
        $this->store->segments['product:' . self::UUID][1] = new FileSegment('name|', 'Armchair');

        $job = $this->glue->import($done, false, false)[0];
        self::assertSame([0, 1], [$job['imported'], $job['source_changed']]);
        self::assertSame([], $this->store->written);

        $job = $this->glue->import($done, true, false)[0];
        self::assertSame([1, 0], [$job['imported'], $job['source_changed']]);
        self::assertSame(['name|' => 'Stuhl'], $this->store->written[0][4]);
    }

    public function testExistingTextIsKeptUnlessOverwriting(): void
    {
        $done              = self::translate($this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'xliff')['content'], ['name|' => 'Stuhl']);
        $this->store->keep = 1;

        $job = $this->glue->import($done, false, false)[0];
        self::assertSame([0, 1], [$job['imported'], $job['existing']]);
        $job = $this->glue->import($done, false, true)[0];
        self::assertSame([1, 0], [$job['imported'], $job['existing']]);
        self::assertTrue($this->store->written[1][5]);
    }

    public function testInvalidMarkupIsNotWritten(): void
    {
        $done = self::translate($this->glue->export('product', self::UUID, 'en_US', ['de_CH'], 'xliff')['content'], ['description|ecommerce' => 'Frisches <ph id="9"/>Brot']);
        $job  = $this->glue->import($done, false, false)[0];
        self::assertSame([0, 1, 1], [$job['imported'], $job['invalid'], $job['untranslated']]);
        self::assertSame([], $this->store->written);
    }

    public function testJobsOfOtherSystemsUnknownReferencesMissingLocalesAndPermissions(): void
    {
        $segments = $this->store->segments['product:' . self::UUID];
        $file     = TranslationFiles::exportXliff([
            new FileJob('Contao-Supertext-Translation', 'page:12:1', 'en', 'de-CH', $segments),
            new FileJob(TranslationFileGlue::PLUGIN, 'entry:12', 'en', 'de-CH', $segments),
            new FileJob(TranslationFileGlue::PLUGIN, 'product:' . self::UUID . '0:en_US:de_CH', 'en', 'de-CH', $segments),
            new FileJob(TranslationFileGlue::PLUGIN, 'product:' . self::UUID . ':en_US:it_IT', 'en', 'it-IT', $segments),
            new FileJob(TranslationFileGlue::PLUGIN, 'product_model:7:en_US:de_CH', 'en', 'de-CH', $segments),
        ]);
        $this->store->denied['product_model'] = true;

        $jobs = $this->glue->import($file, true, true);
        self::assertSame([
            ['file_other_system', ['plugin' => 'Contao-Supertext-Translation']],
            ['file_not_found', []],
            ['file_not_found', []],
            ['file_no_language', ['language' => 'it_IT']],
            ['forbidden_product_model', []],
        ], array_map(static fn (array $j): array => [$j['error']['key'], $j['error']['params']], $jobs));
        self::assertSame('This file belongs to another system (Contao-Supertext-Translation).', $jobs[0]['error']['message']);
        self::assertSame([], $this->store->written);
    }

    public function testHtmlImportedUnchangedWritesTheSourceBack(): void
    {
        $job = $this->glue->import($this->glue->export('product', self::UUID, 'en_US', ['fr_FR'], 'html')['content'], false, false)[0];
        self::assertSame(2, $job['imported']);
        self::assertSame(['fr_FR', 'Chair'], [$this->store->written[0][3], $this->store->written[0][4]['name|']]);
    }

    public function testFileErrors(): void
    {
        self::assertSame('file_invalid', self::code(fn () => $this->glue->import('hello', false, false)));
        self::assertSame('file_missing_metadata', self::code(fn () => $this->glue->import('<html><head></head><body><p data-st-id="a">x</p></body></html>', false, false)));
    }

    public function testFilesOver10MbAreRefusedBeforeReading(): void
    {
        $path   = (string) tempnam(sys_get_temp_dir(), 'st');
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        ftruncate($handle, TranslationFileGlue::MAX_UPLOAD_BYTES + 1);
        fclose($handle);

        try {
            self::assertSame('file_too_large', self::code(fn () => $this->glue->importFile($path, false, false)));
        } finally {
            unlink($path);
        }

        self::assertSame('file_too_large', self::code(fn () => $this->glue->import(str_repeat(' ', TranslationFileGlue::MAX_UPLOAD_BYTES + 1), false, false)));
    }

    public function testEveryFileErrorKeyHasAnEnglishText(): void
    {
        $english = (string) file_get_contents(__DIR__ . '/../../src/Resources/translations/jsmessages.en_US.yml');

        foreach (array_keys(TranslationFileError::MESSAGES) as $key) {
            self::assertMatchesRegularExpression('/^        ' . $key . ':/m', $english, "missing English text for $key");
        }
    }
}
