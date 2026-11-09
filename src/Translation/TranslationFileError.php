<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Translation;

use Supertext\Sdk\Files\TranslationFileError as SdkFileError;

/**
 * An export or import problem, with the UI key (`supertext_translation.error.<key>`) and an English
 * message, like SupertextException. fromSdk() maps the SDK's TranslationFileError codes.
 */
final class TranslationFileError extends \RuntimeException
{
    public const MESSAGES = [
        'file_invalid'            => "This file isn't a valid XLIFF or HTML translation file.",
        'file_missing_metadata'   => "This file wasn't exported by the Supertext bundle, or its header was removed.",
        'file_too_large'          => 'The file is too large (at most 10 MB).',
        'file_nothing'            => 'Nothing to translate.',
        'file_other_system'       => 'This file belongs to another system (%s).',
        'file_not_found'          => 'The product this file was exported from no longer exists.',
        'file_no_language'        => 'The PIM has no activated locale %s.',
        'file_html_one'           => 'An HTML file holds exactly one target language.',
        'forbidden_product'       => 'You are not allowed to edit this product.',
        'forbidden_product_model' => 'You are not allowed to edit this product model.',
        'choose_source'           => 'Choose the language to translate from.',
        'choose_target'           => 'Choose at least one language to translate into.',
    ];

    /** @param array<string, string> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(sprintf(self::MESSAGES[$key] ?? $key, ...array_values($params)));
    }

    public static function fromSdk(SdkFileError $e): self
    {
        return new self(match ($e->errorCode) {
            SdkFileError::MISSING_METADATA => 'file_missing_metadata',
            SdkFileError::TOO_LARGE        => 'file_too_large',
            SdkFileError::INVALID_JOB      => 'file_nothing',
            default                        => 'file_invalid',
        });
    }

    /** @return array{message: string, key: string, params: array<string, string>} */
    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'key' => $this->key, 'params' => $this->params];
    }
}
