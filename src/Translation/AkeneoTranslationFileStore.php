<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Translation;

use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithValuesInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Ramsey\Uuid\Uuid;
use Supertext\Sdk\Files\FileSegment;

/**
 * The Akeneo side of TranslationFileGlue: products by UUID, product models by id, the ACLs the
 * translate dialog uses, the activated locales, the values from EntityTranslator::fileUnits() and
 * the write-back through EntityTranslator::importTexts().
 */
final class AkeneoTranslationFileStore implements TranslationFileStore
{
    private const ACL = [
        'product'       => 'pim_enrich_product_edit_attributes',
        'product_model' => 'pim_enrich_product_model_edit_attributes',
    ];

    public function __construct(
        private readonly object $productRepository,
        private readonly object $productModelRepository,
        private readonly EntityTranslator $translator,
        private readonly SecurityFacade $securityFacade,
    ) {
    }

    public function label(string $type, string $id): ?string
    {
        $entity = $this->entity($type, $id);

        if ($entity === null) {
            return null;
        }

        if ($entity instanceof ProductModelInterface) {
            return (string) $entity->getCode();
        }

        $identifier = (string) $entity->getIdentifier();

        return $identifier !== '' ? $identifier : $id;
    }

    public function canEdit(string $type): bool
    {
        return isset(self::ACL[$type]) && $this->securityFacade->isGranted(self::ACL[$type]);
    }

    public function locales(): array
    {
        return $this->translator->activatedLocales();
    }

    public function segments(string $type, string $id, string $source, string $target): array
    {
        $entity = $this->entity($type, $id);

        if ($entity === null) {
            return [];
        }

        return array_map(
            static fn (TextUnit $unit): FileSegment => new FileSegment($unit->key(), $unit->text, $unit->html),
            $this->translator->fileUnits($entity, $source, $target),
        );
    }

    public function write(string $type, string $id, string $source, string $target, array $texts, bool $overwrite): array
    {
        $entity = $this->entity($type, $id);

        if ($entity === null) {
            return ['status' => 'error', 'translated' => 0, 'existing' => 0, 'message' => 'Not found.', 'key' => 'entity_not_found', 'params' => [], 'detail' => ''];
        }

        return $this->translator->importTexts($entity, $source, $target, $texts, $overwrite);
    }

    private function entity(string $type, string $id): ?EntityWithValuesInterface
    {
        $entity = null;

        if ($type === 'product' && Uuid::isValid($id)) {
            $entity = $this->productRepository->findOneByUuid(Uuid::fromString($id));
        } elseif ($type === 'product_model' && ctype_digit($id)) {
            $entity = $this->productModelRepository->find((int) $id);
        }

        if (!$entity instanceof EntityWithValuesInterface || ($type === 'product_model') !== ($entity instanceof ProductModelInterface)) {
            return null;
        }

        return $entity;
    }
}
