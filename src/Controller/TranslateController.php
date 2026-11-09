<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Controller;

use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithFamilyVariantInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\EntityWithValuesInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\UserManagement\Bundle\Context\UserContext;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Supertext\AkeneoTranslationBundle\Api\SupertextException;
use Supertext\AkeneoTranslationBundle\Reporting\Reporting;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Translation\EntityTranslator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal API behind the "Translate with Supertext" dialog of the product and product model
 * edit forms. Editors need the same permission as for editing the entity's attributes.
 */
final class TranslateController
{
    private const ACL = [
        'product'       => 'pim_enrich_product_edit_attributes',
        'product_model' => 'pim_enrich_product_model_edit_attributes',
    ];

    public function __construct(
        private readonly object $productRepository,
        private readonly object $productModelRepository,
        private readonly EntityTranslator $translator,
        private readonly Settings $settings,
        private readonly SecurityFacade $securityFacade,
        private readonly UserContext $userContext,
        private readonly LoggerInterface $logger,
        private readonly Reporting $reporting,
    ) {
    }

    /** GET: languages, what a translation would do and whether an API key is set. */
    public function contextAction(Request $request, string $type, string $id): JsonResponse
    {
        $entity = $this->entity($type, $id);

        if ($entity instanceof JsonResponse) {
            return $entity;
        }

        $this->reporting->defer(Reporting::ON_REQUEST, self::siteUrl($request));

        $locales = $this->translator->activatedLocales();
        $source  = (string) $request->query->get('from', '');

        if (!\in_array($source, $locales, true)) {
            $source = $this->userContext->getCurrentLocaleCode();
        }

        if (!\in_array($source, $locales, true)) {
            $source = $locales[0] ?? '';
        }

        $uiLocale = (string) $this->userContext->getUiLocaleCode();
        $labels   = [];

        foreach ($locales as $locale) {
            $labels[] = ['code' => $locale, 'label' => self::localeLabel($locale, $uiLocale)];
        }

        $targets = array_values(array_filter($locales, static fn (string $locale): bool => $locale !== $source));

        return new JsonResponse([
            'configured' => $this->settings->apiKey() !== '',
            'links'      => ['signup' => Settings::SIGNUP_URL, 'api_key' => Settings::API_KEY_URL],
            'source'     => $source,
            // Locales without a Supertext language (no mapping, no exact match, no SDK default): nothing can be sent for them.
            'unmapped'   => array_values(array_filter($locales, fn (string $locale): bool => $this->settings->languageCodeOrNull($locale) === null)),
            // A variant's texts usually live on its product model (they are translated there).
            'variant'    => $entity instanceof EntityWithFamilyVariantInterface && $entity->getParent() !== null,
            'locales'    => $labels,
            'preview'    => $this->translator->preview($entity, $source, $targets),
        ]);
    }

    /** POST {"from": "en_US", "to": ["de_DE"], "overwrite": false} */
    public function translateAction(Request $request, string $type, string $id): JsonResponse
    {
        $entity = $this->entity($type, $id);

        if ($entity instanceof JsonResponse) {
            return $entity;
        }

        $siteUrl = self::siteUrl($request);
        $this->reporting->defer(Reporting::ON_REQUEST, $siteUrl);

        $input   = json_decode($request->getContent(), true);
        $locales = $this->translator->activatedLocales();
        $source  = \is_array($input) ? (string) ($input['from'] ?? '') : '';
        $targets = \is_array($input) && \is_array($input['to'] ?? null) ? array_values(array_unique(array_map('strval', $input['to']))) : [];

        if (!\in_array($source, $locales, true)) {
            return new JsonResponse(['message' => 'Choose the language to translate from.', 'key' => 'choose_source'], Response::HTTP_BAD_REQUEST);
        }

        $targets = array_values(array_filter($targets, static fn (string $locale): bool => $locale !== $source && \in_array($locale, $locales, true)));

        if ($targets === []) {
            return new JsonResponse(['message' => 'Choose at least one language to translate into.', 'key' => 'choose_target'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $results = $this->translator->translate($entity, $source, $targets, (bool) ($input['overwrite'] ?? false));
        } catch (SupertextException $e) {
            return new JsonResponse($e->toArray() + [
                'links'   => ['signup' => Settings::SIGNUP_URL, 'api_key' => Settings::API_KEY_URL],
            ], Response::HTTP_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger->error('Supertext translation failed', ['exception' => $e]);

            return new JsonResponse(['message' => 'The translation could not be saved. See the application log for details.', 'key' => 'save_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Supertext rejected the key (401/403): the stored key check becomes "invalid" (reported if it changed).
        $this->reporting->deferAfterTranslation($siteUrl, $results);

        return new JsonResponse(['results' => $results]);
    }

    private static function siteUrl(Request $request): ?string
    {
        return Settings::siteUrl($request->getSchemeAndHttpHost() . $request->getBasePath());
    }

    private function entity(string $type, string $id): EntityWithValuesInterface|JsonResponse
    {
        if (!isset(self::ACL[$type])) {
            return new JsonResponse(['message' => 'Unknown entity type.', 'key' => 'unknown_type'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->securityFacade->isGranted(self::ACL[$type])) {
            return new JsonResponse(['message' => 'You are not allowed to edit this ' . str_replace('_', ' ', $type) . '.', 'key' => 'forbidden_' . $type], Response::HTTP_FORBIDDEN);
        }

        $entity = null;

        if ($type === 'product' && Uuid::isValid($id)) {
            $entity = $this->productRepository->findOneByUuid(Uuid::fromString($id));
        } elseif ($type === 'product_model' && ctype_digit($id)) {
            $entity = $this->productModelRepository->find((int) $id);
        }

        if (!$entity instanceof EntityWithValuesInterface || ($type === 'product_model') !== ($entity instanceof ProductModelInterface)) {
            return new JsonResponse(['message' => 'Not found.', 'key' => 'entity_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $entity;
    }

    private static function localeLabel(string $locale, string $uiLocale): string
    {
        $label = class_exists(\Locale::class) ? (string) \Locale::getDisplayName($locale, $uiLocale !== '' ? $uiLocale : 'en_US') : '';

        return $label !== '' && $label !== $locale ? $label : $locale;
    }
}
