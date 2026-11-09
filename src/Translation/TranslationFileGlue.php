<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Translation;

use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\Sdk\Files\FileJob;
use Supertext\Sdk\Files\ImportedSegment;
use Supertext\Sdk\Files\TranslationFileError as SdkFileError;
use Supertext\Sdk\Files\TranslationFiles;

/**
 * Export for translation / import translation (XLIFF 1.2 or HTML) with the Supertext SDK's
 * TranslationFiles. No Akeneo or Symfony classes (unit-tested with a fake store); the store
 * (AkeneoTranslationFileStore) collects the values with EntityTranslator and writes the imported
 * texts back through it, with the same rules as API translations.
 *
 * Job reference: `<product|product_model>:<uuid or id>:<source locale>:<target locale>`, e.g.
 * `product:0a1b…:en_US:de_DE` (the source locale is part of it because Akeneo has no fixed source
 * language: the import collects the values of the same locale again).
 */
final class TranslationFileGlue
{
    /** The job's `plugin`: this repository's name. */
    public const PLUGIN = 'Akeneo-Supertext-Translation';
    public const SHORT_NAME = 'akeneo';
    public const FORMAT_XLIFF = 'xliff';
    public const FORMAT_HTML = 'html';
    /** Uploads larger than this are refused before they are read (10 MB). */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const TYPES = ['product', 'product_model'];

    public function __construct(private readonly TranslationFileStore $store, private readonly Settings $settings)
    {
    }

    public static function reference(string $type, string $id, string $source, string $target): string
    {
        return $type . ':' . $id . ':' . $source . ':' . $target;
    }

    /** @return array{type: string, id: string, source: string, target: string}|null null: not a reference of this bundle */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('/^(product|product_model):([0-9a-fA-F-]{1,36}):([A-Za-z0-9_]{2,20}):([A-Za-z0-9_]{2,20})$/D', $reference, $m) !== 1) {
            return null;
        }

        return ['type' => $m[1], 'id' => $m[2], 'source' => $m[3], 'target' => $m[4]];
    }

    /**
     * `akeneo-<id>-<target>.xlf` (or `.html`), `akeneo-<id>.xlf` for several targets.
     *
     * @param list<string> $targets Supertext target codes
     */
    public static function fileName(string $id, array $targets, string $format): string
    {
        $name = self::SHORT_NAME . '-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $id);

        if (\count($targets) === 1) {
            $name .= '-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $targets[0]);
        }

        return $name . ($format === self::FORMAT_HTML ? '.html' : '.xlf');
    }

    public static function contentType(string $format): string
    {
        return $format === self::FORMAT_HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
    }

    /** The Supertext target code of a locale (mapping, exact match, default), else the locale with `-` for `_`. */
    public function targetCode(string $locale): string
    {
        return $this->settings->languageCodeOrNull($locale) ?? str_replace('_', '-', $locale);
    }

    /** The Supertext source code of a locale (the mapping in its source form, else the bare language), else the locale with `-` for `_`. */
    public function sourceCode(string $locale): string
    {
        try {
            return $this->settings->sourceCode($locale);
        } catch (\Throwable) {
            return str_replace('_', '-', $locale);
        }
    }

    /**
     * @param list<string> $targets target locales (HTML: exactly one)
     *
     * @return array{name: string, type: string, content: string}
     *
     * @throws TranslationFileError forbidden_*, file_not_found, choose_source, choose_target, file_html_one, file_nothing
     */
    public function export(string $type, string $id, string $source, array $targets, string $format): array
    {
        if (!\in_array($type, self::TYPES, true) || !\in_array($format, [self::FORMAT_XLIFF, self::FORMAT_HTML], true)) {
            throw new TranslationFileError('file_invalid');
        }

        if (!$this->store->canEdit($type)) {
            throw new TranslationFileError('forbidden_' . $type);
        }

        if ($this->store->label($type, $id) === null) {
            throw new TranslationFileError('file_not_found');
        }

        $locales = $this->store->locales();

        if (!\in_array($source, $locales, true)) {
            throw new TranslationFileError('choose_source');
        }

        $targets = array_values(array_unique(array_filter($targets, static fn (string $locale): bool => $locale !== $source && \in_array($locale, $locales, true))));

        if ($targets === []) {
            throw new TranslationFileError('choose_target');
        }

        if ($format === self::FORMAT_HTML && \count($targets) !== 1) {
            throw new TranslationFileError('file_html_one');
        }

        $jobs  = [];
        $codes = [];

        foreach ($targets as $target) {
            $segments = $this->store->segments($type, $id, $source, $target);

            if ($segments === []) {
                continue;
            }

            $codes[] = $code = $this->targetCode($target);
            $jobs[]  = new FileJob(self::PLUGIN, self::reference($type, $id, $source, $target), $this->sourceCode($source), $code, $segments);
        }

        if ($jobs === []) {
            throw new TranslationFileError('file_nothing');
        }

        try {
            $content = $format === self::FORMAT_HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (SdkFileError $e) {
            throw TranslationFileError::fromSdk($e);
        }

        return ['name' => self::fileName($id, $codes, $format), 'type' => self::contentType($format), 'content' => $content];
    }

    /**
     * Imports an uploaded file; files over MAX_UPLOAD_BYTES are refused before reading.
     *
     * @return list<array<string, mixed>> see import()
     *
     * @throws TranslationFileError file_too_large, file_invalid, file_missing_metadata
     */
    public function importFile(string $path, bool $includeChanged, bool $overwrite): array
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false) {
            throw new TranslationFileError('file_invalid');
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new TranslationFileError('file_too_large');
        }

        return $this->import((string) file_get_contents($path), $includeChanged, $overwrite);
    }

    /**
     * @return list<array{reference: string, label: string, locale: string, error: array{message: string, key: string, params: array<string, string>}|null, imported: int, source_changed: int, untranslated: int, invalid: int, missing: int, existing: int, problem: array{message: string, key: string, params: array<string, string|int>, detail: string}|null}>
     *         one summary per job of the file; `error`: the job wasn't imported; `problem`: the write-back's message
     *
     * @throws TranslationFileError file_too_large, file_invalid, file_missing_metadata
     */
    public function import(string $content, bool $includeChanged, bool $overwrite): array
    {
        if (\strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new TranslationFileError('file_too_large');
        }

        try {
            $files = TranslationFiles::read($content);
        } catch (SdkFileError $e) {
            throw TranslationFileError::fromSdk($e);
        }

        $results = [];

        foreach ($files as $file) {
            $job = [
                'reference' => $file->reference, 'label' => '', 'locale' => $file->targetLanguage, 'error' => null,
                'imported' => 0, 'source_changed' => 0, 'untranslated' => 0, 'invalid' => 0, 'missing' => 0, 'existing' => 0, 'problem' => null,
            ];
            $reference = self::parseReference($file->reference);
            $label     = null;

            if ($file->plugin !== self::PLUGIN) {
                $job['error'] = (new TranslationFileError('file_other_system', ['plugin' => $file->plugin]))->toArray();
            } elseif ($reference === null) {
                $job['error'] = (new TranslationFileError('file_not_found'))->toArray();
            } elseif (!$this->store->canEdit($reference['type'])) {
                $job['error'] = (new TranslationFileError('forbidden_' . $reference['type']))->toArray();
            } elseif (($label = $this->store->label($reference['type'], $reference['id'])) === null) {
                $job['error'] = (new TranslationFileError('file_not_found'))->toArray();
            } elseif (!\in_array($reference['target'], $locales = $this->store->locales(), true) || !\in_array($reference['source'], $locales, true)) {
                $job['label'] = $label;
                $job['error'] = (new TranslationFileError('file_no_language', ['language' => \in_array($reference['source'], $locales, true) ? $reference['target'] : $reference['source']]))->toArray();
            }

            if ($job['error'] !== null || $reference === null || $label === null) {
                $results[] = $job;

                continue;
            }

            $job['label']  = $label;
            $job['locale'] = $reference['target'];
            $texts         = [];
            $current       = $this->store->segments($reference['type'], $reference['id'], $reference['source'], $reference['target']);

            foreach (TranslationFiles::importTranslations($file, $current) as $segment) {
                switch ($segment->status) {
                    case ImportedSegment::OK:
                        $texts[$segment->key] = (string) $segment->text;
                        break;
                    case ImportedSegment::SOURCE_CHANGED:
                        if ($includeChanged) {
                            $texts[$segment->key] = (string) $segment->text;
                        } else {
                            $job['source_changed']++;
                        }
                        break;
                    case ImportedSegment::UNTRANSLATED:
                        $job['untranslated']++;
                        break;
                    case ImportedSegment::INVALID_MARKUP:
                        $job['invalid']++;
                        break;
                    default:
                        $job['missing']++;
                }
            }

            if ($texts !== []) {
                $written         = $this->store->write($reference['type'], $reference['id'], $reference['source'], $reference['target'], $texts, $overwrite);
                $job['imported'] = $written['translated'];
                $job['existing'] = $written['existing'];

                if ($written['key'] !== '' || $written['status'] === 'error') {
                    $job['problem'] = ['message' => $written['message'], 'key' => $written['key'], 'params' => $written['params'], 'detail' => $written['detail']];
                }
            }

            $results[] = $job;
        }

        return $results;
    }
}
