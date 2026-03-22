<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Counting transport that returns the given responses in sequence.
 */
final class RetrySequenceTransport implements TransportInterface
{
    private int $callCount = 0;

    /**
     * @param list<HttpResponse|HttpClientException> $sequence
     */
    public function __construct(private readonly array $sequence)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $item = $this->sequence[$this->callCount] ?? new HttpResponse(200, '');
        $this->callCount++;

        if ($item instanceof HttpClientException) {
            throw $item;
        }

        return $item;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }
}

/**
 * Class RetryTest
 *
 * Tests HttpRequest::retry().
 *
 * @package Tests
 */
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(FakeTransport::class)]
#[UsesClass(Http::class)]
final class RetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::resetClient();
    }

    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_no_retry_by_default(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(500, 'error'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')->send();

        $this->assertSame(500, $response->status());
        $this->assertSame(1, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_retries_on_5xx_by_default(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(500, 'error'),
            new HttpResponse(200, 'ok'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0)
            ->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_does_not_retry_on_4xx_by_default(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(404, 'not found'),
            new HttpResponse(200, 'ok'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0)
            ->send();

        $this->assertSame(404, $response->status());
        $this->assertSame(1, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_respects_custom_when_condition(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(429, 'rate limited'),
            new HttpResponse(200, 'ok'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0, fn ($r) => $r->status() === 429)
            ->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_retries_up_to_max_times(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(500, 'error 1'),
            new HttpResponse(500, 'error 2'),
            new HttpResponse(200, 'ok'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(2, 0)
            ->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_returns_last_response_when_retries_exhausted(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpResponse(500, 'error 1'),
            new HttpResponse(500, 'error 2'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0)
            ->send();

        // retry(1) = 2 total attempts; both fail; last response returned
        $this->assertSame(500, $response->status());
        $this->assertSame(2, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_retries_on_http_client_exception(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpClientException('Network error'),
            new HttpResponse(200, 'ok'),
        ]);

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0)
            ->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $transport->getCallCount());
    }

    /**
     * @return void
     */
    public function test_throws_exception_when_all_attempts_fail_with_exception(): void
    {
        $transport = new RetrySequenceTransport([
            new HttpClientException('Error 1'),
            new HttpClientException('Error 2'),
        ]);

        $client = new HttpClient($transport);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Error 2');

        $client->get('https://api.example.com')
            ->retry(1, 0)
            ->send();
    }

    /**
     * @return void
     */
    public function test_retry_with_fake_transport(): void
    {
        $call = 0;
        $transport = new class ($call) implements TransportInterface {
            public function __construct(private int &$callRef)
            {
            }

            /** @param array<string, string> $headers */
            public function send(string $method, string $url, array $headers, string $body): HttpResponse
            {
                $this->callRef++;
                return new HttpResponse($this->callRef < 3 ? 503 : 200, '');
            }
        };

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(3, 0)
            ->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(3, $call);
    }
}
