<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Translation;

use Supertext\Sdk\Files\FileSegment;

/**
 * What TranslationFileGlue needs from Akeneo (AkeneoTranslationFileStore): products and product
 * models, permissions, locales, the segments (EntityTranslator::fileUnits) and the write-back
 * (EntityTranslator::importTexts). The unit tests use a fake.
 */
interface TranslationFileStore
{
    /** The entity's label (identifier or label), null when it doesn't exist. $type: product or product_model. */
    public function label(string $type, string $id): ?string;

    /** Whether the current user may edit the attributes of this entity type (the same ACL as translating). */
    public function canEdit(string $type): bool;

    /** @return list<string> the activated locales */
    public function locales(): array;

    /** @return list<FileSegment> the values a file for $target holds, keyed by TextUnit::key() */
    public function segments(string $type, string $id, string $source, string $target): array;

    /**
     * Writes imported texts with EntityTranslator's rules.
     *
     * @param array<string, string> $texts TextUnit::key() => text
     *
     * @return array{status: string, translated: int, existing: int, message: string, key: string, params: array<string, string|int>, detail: string}
     */
    public function write(string $type, string $id, string $source, string $target, array $texts, bool $overwrite): array;
}
