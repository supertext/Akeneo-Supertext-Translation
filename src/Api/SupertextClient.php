<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for Akeneo PIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\AkeneoTranslationBundle\Api;

use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\V1\Client;
use Supertext\Sdk\V1\Segment;
use Supertext\Sdk\V1\SupertextException as SdkException;

/**
 * The bundle's access to Supertext: a thin layer over the Supertext SDK for PHP
 * (lib/supertext-sdk, https://github.com/Supertext/Supertext-API-SDK-and-Guide), which does the
 * protocol (upload, polling, download, delete), HTML packing and the HTTP 429 retries. Language
 * codes are resolved before (Settings::languageCode(), the SDK's Languages): this class sends
 * them as they are. This class adds the bundle's environments and turns SDK errors into the bundle's
 * SupertextException with a UI message key.
 *
 * Translation review: forReview() returns a second client with the same key and settings whose base
 * URL is the review service (REVIEW_URL by default), for locales with "Translation review" on. The SDK's
 * translateTexts() uploads, polls, downloads and deletes with one client, so a job is always collected
 * by the client that submitted it.
 *
 * Has no Akeneo dependencies (unit-tested with a fake transport).
 */
final class SupertextClient
{
    public const LIVE = 'https://api.supertext.com/v1/';
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks the
     * same API and forwards to the live Supertext API with the plugin's key, keeping every translation
     * so reviewers can correct them on the website. Used for locales with "Translation review" on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    private readonly string $apiKey;
    private readonly Client $client;

    /** @var (\Closure(): self)|null builds the client for the review service (null: none) */
    private readonly ?\Closure $makeReviewClient;

    private ?self $reviewClient = null;

    /**
     * @param int                          $timeout        seconds to wait for one translation
     * @param string                       $userAgent      e.g. "Akeneo-Supertext-Translation/1.0.0"
     * @param HttpTransport|null           $transport      the SDK's curl transport by default
     * @param (callable(float): void)|null $sleep          for tests
     * @param string                       $reviewUrl      review service for locales with Translation review ('' = none)
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl,
        int $timeout = 180,
        string $userAgent = '',
        ?HttpTransport $transport = null,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
        string $reviewUrl = '',
    ) {
        $baseUrl      = $baseUrl !== '' ? $baseUrl : self::LIVE;
        $reviewUrl    = trim($reviewUrl);
        $this->apiKey = self::normalizeKey($apiKey);
        $this->makeReviewClient = $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim($baseUrl, '/')
            ? null
            : static fn (): self => new self($apiKey, $reviewUrl, $timeout, $userAgent, $transport, $pollInterval, $sleep);
        $this->client = new Client(
            $this->apiKey,
            $baseUrl,
            pollInterval: $pollInterval,
            timeout: (float) $timeout,
            requestTimeout: 60.0,
            userAgent: $userAgent !== '' ? $userAgent : null,
            transport: $transport,
            sleep: $sleep,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(#[\SensitiveParameter] string $key): string
    {
        return trim((string) preg_replace('/^\s*Supertext-Auth-Key(?:\s+|$)/i', '', $key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    /**
     * The client for a target locale: the review service when its "Translation review" is on (same key,
     * same protocol), else this one. Without a review URL, always this one.
     */
    public function forReview(bool $review): self
    {
        if (!$review || $this->makeReviewClient === null) {
            return $this;
        }

        return $this->reviewClient ??= ($this->makeReviewClient)();
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Cost-free check that the key is accepted.
     *
     * @throws SupertextException
     */
    public function validateApiKey(): void
    {
        try {
            $this->client->validateApiKey();
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }

    /**
     * Translates keyed texts (plain text or HTML) and returns them under the same keys.
     *
     * @template K of array-key
     *
     * @param array<K, Segment> $segments
     * @param string            $targetLanguage Supertext target code, e.g. "de-CH" or "es" (sent as it is)
     * @param string            $sourceLanguage Supertext source code, e.g. "en" (sent as it is). Empty: auto-detect.
     * @param string            $politeness     "default", "more" (formal) or "less" (informal)
     *
     * @return array<K, string>
     *
     * @throws SupertextException
     */
    public function translateTexts(array $segments, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): array
    {
        try {
            return $this->client->translateTexts($segments, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness);
        } catch (SdkException $e) {
            throw SupertextException::fromSdk($e);
        }
    }
}
