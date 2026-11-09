<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Controller;

use Akeneo\UserManagement\Bundle\Context\UserContext;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Supertext\AkeneoTranslationBundle\Api\SupertextClient;
use Supertext\AkeneoTranslationBundle\Reporting\Reporting;
use Supertext\AkeneoTranslationBundle\Settings\Settings;
use Supertext\AkeneoTranslationBundle\Translation\EntityTranslator;
use Supertext\PluginHealth\KeyCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal API behind System → Supertext. Needs Akeneo's "System configuration" permission
 * (oro_config_system), like System → Configuration. The API key is never sent back.
 *
 * Commando Center: opening the page runs the reporter's onRequest after the response, saving
 * runs afterSettingsSaved (its key check is shown on the page), Test connection runs testConnection.
 */
final class SettingsController
{
    public const ACL = 'oro_config_system';

    public function __construct(
        private readonly Settings $settings,
        private readonly EntityTranslator $translator,
        private readonly SecurityFacade $securityFacade,
        private readonly UserContext $userContext,
        private readonly Reporting $reporting,
    ) {
    }

    public function getAction(Request $request): JsonResponse
    {
        if (!$this->securityFacade->isGranted(self::ACL)) {
            return new JsonResponse(['message' => 'You are not allowed to change the system configuration.', 'key' => 'forbidden_settings'], Response::HTTP_FORBIDDEN);
        }

        $this->reporting->defer(Reporting::ON_REQUEST, self::siteUrl($request));

        return new JsonResponse($this->payload($request));
    }

    public function saveAction(Request $request): JsonResponse
    {
        if (!$this->securityFacade->isGranted(self::ACL)) {
            return new JsonResponse(['message' => 'You are not allowed to change the system configuration.', 'key' => 'forbidden_settings'], Response::HTTP_FORBIDDEN);
        }

        $input  = json_decode($request->getContent(), true);
        $errors = $this->settings->update(\is_array($input) ? $input : []);

        if ($errors !== []) {
            return new JsonResponse(['message' => implode(' ', array_column($errors, 'message')), 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The automatic API key check (cost-free) and the settings_saved report; never fails the save.
        $result = $this->reporting->run(Reporting::AFTER_SETTINGS_SAVED, self::siteUrl($request));

        return new JsonResponse($this->payload($request, $result?->check));
    }

    /** Cost-free check of the key (the one typed in the form, else the saved one). */
    public function testAction(Request $request): JsonResponse
    {
        if (!$this->securityFacade->isGranted(self::ACL)) {
            return new JsonResponse(['message' => 'You are not allowed to change the system configuration.', 'key' => 'forbidden_settings'], Response::HTTP_FORBIDDEN);
        }

        $input = json_decode($request->getContent(), true);
        $typed = \is_array($input) ? SupertextClient::normalizeKey((string) ($input['api_key'] ?? '')) : '';
        $key   = $this->settings->apiKeySource() !== 'environment' && $typed !== '' ? $typed : $this->settings->apiKey();
        $url   = $this->settings->baseUrl();

        if (\is_array($input) && !$this->settings->baseUrlFromEnvironment() && \in_array($input['environment'] ?? null, Settings::ENVIRONMENTS, true)) {
            $url = SupertextClient::baseUrlFor((string) $input['environment'], $input['environment'] === 'custom' ? (string) ($input['api_url'] ?? '') : '');
        }

        // The same automatic key check as after saving (stored and shown on the page; a changed result is reported).
        $result = $this->reporting->run(Reporting::TEST_CONNECTION, self::siteUrl($request), $key, $url);
        $check  = $result?->check;

        return new JsonResponse([
            'ok'        => $check?->status === KeyCheck::OK,
            'key_check' => Reporting::checkToArray($check),
            'message'   => $check !== null ? Reporting::checkMessage($check) : 'The API key could not be checked.',
        ]);
    }

    /** The site's base URL: AKENEO_PIM_URL, else scheme, host and base path of the request. */
    private static function siteUrl(Request $request): ?string
    {
        return Settings::siteUrl($request->getSchemeAndHttpHost() . $request->getBasePath());
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?KeyCheck $check = null): array
    {
        $key       = $this->settings->apiKey();
        $uiLocale  = (string) $this->userContext->getUiLocaleCode();
        $uiLocale  = $uiLocale !== '' ? $uiLocale : 'en_US';
        $languages = [];

        foreach ($this->translator->activatedLocales() as $locale) {
            $stored      = $this->settings->languages()[$locale] ?? [];
            $suggested   = Settings::suggestedLanguageCode($locale);
            $languages[] = [
                'locale'         => $locale,
                'label'          => (string) \Locale::getDisplayName($locale, $uiLocale),
                // The saved mapping, else the suggestion (exact match or SDK default, preselected), else ""
                // ("— choose —": no default, nothing can be sent until it is mapped).
                'code'           => (string) ($stored['code'] ?? $suggested ?? ''),
                'suggested_code' => (string) $suggested,
                'politeness'     => $this->settings->politeness($locale),
                'review'         => $this->settings->review($locale),
            ];
        }

        $version = Settings::version();
        $siteUrl = self::siteUrl($request);

        return [
            'version'                  => $version,
            'release_url'              => Settings::releaseUrl($version),
            'api_key_source'           => $this->settings->apiKeySource(),
            'api_key_hint'             => $key !== '' ? '…' . substr($key, -4) : '',
            'environment'              => $this->settings->environment(),
            'api_url'                  => $this->settings->customUrl(),
            'api_url_from_environment' => $this->settings->baseUrlFromEnvironment(),
            'effective_url'            => $this->settings->baseUrl(),
            // '' when SUPERTEXT_REVIEW_URL turns Translation review off for every locale.
            'review_url'               => $this->settings->reviewUrl(),
            'timeout'                  => $this->settings->timeout(),
            'languages'                => $languages,
            // Supertext's target languages (code, localized name, politeness) for the mapping's drop-downs.
            'targets'                  => Settings::targetOptions($uiLocale),
            'version_reports'          => $this->settings->versionReports() ?? true,
            'version_reports_from_environment' => $this->settings->versionReportsFromEnvironment(),
            'version_reports_enabled'  => $this->settings->versionReportsEnabled(),
            'ping_url'                 => $siteUrl !== null ? Reporting::pingUrl($siteUrl) : '',
            'key_check'                => Reporting::checkToArray($check ?? $this->reporting->lastCheck()),
            'links'                    => ['signup' => Settings::SIGNUP_URL, 'api_key' => Settings::API_KEY_URL],
        ];
    }
}
