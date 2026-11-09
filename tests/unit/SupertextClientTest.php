<?php

namespace Supertext\AkeneoTranslationBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\AkeneoTranslationBundle\Api\SupertextClient;
use Supertext\AkeneoTranslationBundle\Api\SupertextException;
use Supertext\Sdk\Http\HttpRequest;
use Supertext\Sdk\Http\HttpResponse;
use Supertext\Sdk\Http\HttpTransport;
use Supertext\Sdk\V1\Segment;

/**
 * The bundle's layer over the Supertext SDK (lib/supertext-sdk): environments, key handling and
 * the mapping of SDK errors to UI keys. The protocol itself is tested in the SDK repository.
 */
final class SupertextClientTest extends TestCase
{
    /** @var \ArrayObject<int, HttpRequest> */
    private \ArrayObject $requests;

    protected function setUp(): void
    {
        $this->requests = new \ArrayObject();
    }

    /** @param callable(HttpRequest): HttpResponse $handler */
    private function client(callable $handler, string $key = 'test-key', string $reviewUrl = ''): SupertextClient
    {
        $transport = new class ($handler, $this->requests) implements HttpTransport {
            /** @param \ArrayObject<int, HttpRequest> $requests */
            public function __construct(private $handler, private \ArrayObject $requests)
            {
            }

            public function send(HttpRequest $request): HttpResponse
            {
                $this->requests[] = $request;

                return ($this->handler)($request);
            }
        };

        return new SupertextClient($key, 'https://api.test/v1', 10, 'Akeneo-Supertext-Translation/test', $transport, 0.01, static function (): void {
        }, $reviewUrl);
    }

    private static function api(string $translation): callable
    {
        return static fn (HttpRequest $r): HttpResponse => match (true) {
            $r->method === 'POST'                    => new HttpResponse(200, '{"file_id":"f1"}'),
            str_ends_with($r->url, '/status')        => new HttpResponse(200, '{"status":"done"}'),
            str_ends_with($r->url, '/translation')   => new HttpResponse(200, $translation),
            default                                  => new HttpResponse(200, '{}'),
        };
    }

    public function testTranslatesTextsAndHtmlByKey(): void
    {
        $client = $this->client(self::api(
            '<div data-st-id="0">Hallo &amp; willkommen<br>Zweite Zeile</div><div data-st-id="1"><p>Von <strong>Hand</strong></p></div>'
        ));

        $result = $client->translateTexts(
            [3 => Segment::text("Hello & welcome\nSecond line"), 7 => Segment::html('<p>By <strong>hand</strong></p>')],
            'de-CH',
            'en',
            'more',
        );

        self::assertSame([3 => "Hallo & willkommen\nZweite Zeile", 7 => '<p>Von <strong>Hand</strong></p>'], $result);
        self::assertSame(['POST', 'GET', 'GET', 'DELETE'], array_map(static fn (HttpRequest $r): string => $r->method, $this->requests->getArrayCopy()));

        $post = $this->requests[0];
        self::assertSame('https://api.test/v1/translate/ai/file', $post->url);
        self::assertSame('Supertext-Auth-Key test-key', $post->headers['Authorization']);
        self::assertStringContainsString('Akeneo-Supertext-Translation/test', $post->headers['User-Agent']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", (string) $post->body);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", (string) $post->body);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", (string) $post->body);
    }

    public function testAcceptsTheKeyWithItsPrefix(): void
    {
        $client = $this->client(self::api(''), '  Supertext-Auth-Key abc+/= ');
        $client->validateApiKey();

        self::assertTrue($client->hasApiKey());
        self::assertSame('Supertext-Auth-Key abc+/=', $this->requests[0]->headers['Authorization']);
        self::assertSame('abc', SupertextClient::normalizeKey('Supertext-Auth-Key abc'));
        self::assertSame('', SupertextClient::normalizeKey('Supertext-Auth-Key '));
    }

    public function testMapsSdkErrorsToUiKeys(): void
    {
        $cases = [
            'auth_failed'           => new HttpResponse(401, 'Unauthorized'),
            'invalid_language_pair' => new HttpResponse(400, '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx"}'),
            'unavailable'           => new HttpResponse(503, ''),
            'http_status'           => new HttpResponse(418, ''),
        ];

        foreach ($cases as $key => $response) {
            try {
                $this->client(static fn (): HttpResponse => $response)->validateApiKey();
                self::fail('No exception for ' . $key);
            } catch (SupertextException $e) {
                self::assertSame($key, $e->key);
                self::assertSame($response->status, $e->params['status']);
            }
        }

        try {
            $this->client(static fn (): HttpResponse => new HttpResponse(400, '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx"}'))->validateApiKey();
        } catch (SupertextException $e) {
            self::assertSame(['status' => 400, 'source' => 'en', 'target' => 'xx'], $e->params);
        }
    }

    public function testPolitenessATargetDoesNotOfferIsUnsupportedFeature(): void
    {
        $client = $this->client(static fn (HttpRequest $r): HttpResponse => new HttpResponse(400, '{"error_code":"FEATURE_ERROR","feature":"politeness"}'));

        try {
            $client->translateTexts([Segment::text('Hello')], 'en-US', 'de', 'more');
            self::fail('No exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_feature', $e->key);
        }
    }

    public function testAuthenticationErrorsLinkToTheApiKeyPage(): void
    {
        try {
            $this->client(static fn (): HttpResponse => new HttpResponse(403, ''))->validateApiKey();
            self::fail('No exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('https://www.supertext.com/en/integrations/api', $e->getMessage());
            self::assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function testStopsOnLimitExceededAndCleansUp(): void
    {
        $client = $this->client(static fn (HttpRequest $r): HttpResponse => match (true) {
            $r->method === 'POST'             => new HttpResponse(200, '{"file_id":"f1"}'),
            str_ends_with($r->url, '/status') => new HttpResponse(200, '{"status":"limit_exceeded"}'),
            default                           => new HttpResponse(200, '{}'),
        });

        try {
            $client->translateTexts([Segment::text('Hello')], 'de-CH');
            self::fail('No exception');
        } catch (SupertextException $e) {
            self::assertSame('limit_exceeded', $e->key);
        }

        $all = $this->requests->getArrayCopy();
        self::assertSame('DELETE', end($all)->method);
    }

    public function testRefusesToRunWithoutKey(): void
    {
        $client = $this->client(self::api(''), '   ');

        self::assertFalse($client->hasApiKey());

        try {
            $client->translateTexts([Segment::text('Hello')], 'de-CH');
            self::fail('No exception');
        } catch (SupertextException $e) {
            self::assertSame('no_api_key', $e->key);
        }

        self::assertCount(0, $this->requests);
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame(SupertextClient::TESTING, SupertextClient::baseUrlFor('testing'));
        self::assertSame('https://example.test/v1/', SupertextClient::baseUrlFor('custom', ' https://example.test/v1/ '));
    }

    public function testEveryUiKeyExistsInTheTranslations(): void
    {
        $messages = (string) file_get_contents(__DIR__ . '/../../src/Resources/translations/jsmessages.en_US.yml');

        foreach (SupertextException::SDK_KEYS as $key) {
            self::assertMatchesRegularExpression('/^        ' . preg_quote($key, '/') . ':/m', $messages, $key);
        }
    }

    public function testReviewLocalesGoThroughTheReviewService(): void
    {
        $client = $this->client(self::api('<div data-st-id="0">Hallo</div>'), reviewUrl: 'https://review.test/v1/');

        self::assertSame($client, $client->forReview(false));
        $review = $client->forReview(true);
        self::assertNotSame($client, $review);
        self::assertSame($review, $client->forReview(true), 'one review client');

        self::assertSame(['Hallo'], $review->translateTexts([Segment::text('Hello')], 'de-CH', 'en'));
        self::assertSame(['Hallo'], $client->translateTexts([Segment::text('Hello')], 'fr-CH', 'en'));

        $calls = array_map(static fn (HttpRequest $r): array => [(string) parse_url($r->url, PHP_URL_HOST), $r->method], $this->requests->getArrayCopy());
        self::assertSame(
            [['review.test', 'POST'], ['review.test', 'GET'], ['review.test', 'GET'], ['review.test', 'DELETE'], ['api.test', 'POST'], ['api.test', 'GET'], ['api.test', 'GET'], ['api.test', 'DELETE']],
            $calls,
            'German (review on): upload, status, download and delete through the review service; French directly',
        );
        self::assertSame('Supertext-Auth-Key test-key', $this->requests[0]->headers['Authorization'], 'same key');
        self::assertStringContainsString('Akeneo-Supertext-Translation/test', $this->requests[0]->headers['User-Agent'], 'same User-Agent');

        $client->validateApiKey();
        self::assertSame('https://api.test/v1/features', $this->requests[\count($this->requests) - 1]->url, 'the key check goes directly');
    }

    public function testReviewOffWithoutReviewUrl(): void
    {
        $client = $this->client(self::api('<div data-st-id="0">Hallo</div>'));
        self::assertSame($client, $client->forReview(true), 'empty review URL: review off');

        $client = $this->client(self::api('<div data-st-id="0">Hallo</div>'), reviewUrl: 'https://api.test/v1/');
        self::assertSame($client, $client->forReview(true), 'same URL as the API: no second client');
    }
}
