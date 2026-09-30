<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\Backoff;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\RetryAfter;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Returns the given responses in order.
 */
final class RetryAfterSequenceTransport implements TransportInterface
{
    private int $calls = 0;

    /**
     * @param list<HttpResponse> $sequence
     */
    public function __construct(private readonly array $sequence)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
    {
        return $this->sequence[$this->calls++] ?? new HttpResponse(200, '');
    }
}

/**
 * Retry-After support: parsing, and preferring it over the backoff delay.
 *
 * @package Tests
 */
#[CoversClass(RetryAfter::class)]
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(Backoff::class)]
final class RetryAfterTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function test_parses_delta_seconds(): void
    {
        self::assertSame(120_000, RetryAfter::delayMs('120', self::NOW));
        self::assertSame(0, RetryAfter::delayMs('0', self::NOW));
    }

    public function test_parses_an_http_date_relative_to_now(): void
    {
        $in30s = gmdate('D, d M Y H:i:s \G\M\T', self::NOW + 30);

        self::assertSame(30_000, RetryAfter::delayMs($in30s, self::NOW));
    }

    public function test_a_date_in_the_past_means_no_wait(): void
    {
        self::assertSame(0, RetryAfter::delayMs(gmdate('D, d M Y H:i:s \G\M\T', self::NOW - 10), self::NOW));
    }

    public function test_invalid_values_are_ignored(): void
    {
        foreach (['', 'soon', '-5', '1.5', '10s'] as $value) {
            self::assertNull(RetryAfter::delayMs($value, self::NOW), $value);
        }
    }

    public function test_retry_after_replaces_the_backoff_delay(): void
    {
        $backoffCalls = 0;
        $transport = new RetryAfterSequenceTransport([
            new HttpResponse(503, '', ['retry-after' => '0']),
            new HttpResponse(200, 'ok'),
        ]);

        $response = (new HttpRequest('GET', 'https://x.test', $transport))
            ->retry(2, 5000)
            ->backoff($this->countingBackoff($backoffCalls))
            ->respectRetryAfter()
            ->send();

        self::assertSame(200, $response->status());
        self::assertSame(0, $backoffCalls);
    }

    public function test_backoff_still_applies_without_a_retry_after_header(): void
    {
        $backoffCalls = 0;
        $transport = new RetryAfterSequenceTransport([new HttpResponse(503, ''), new HttpResponse(200, 'ok')]);

        (new HttpRequest('GET', 'https://x.test', $transport))
            ->retry(2)
            ->backoff($this->countingBackoff($backoffCalls))
            ->respectRetryAfter()
            ->send();

        self::assertSame(1, $backoffCalls);
    }

    public function test_the_wait_is_capped_by_max_ms(): void
    {
        $transport = new RetryAfterSequenceTransport([
            new HttpResponse(429, '', ['retry-after' => '3600']),
            new HttpResponse(200, 'ok'),
        ]);

        $started = microtime(true);
        $response = (new HttpRequest('GET', 'https://x.test', $transport))->retry(1, 0)->respectRetryAfter(maxMs: 0)->send();

        self::assertSame(200, $response->status());
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_429_is_retried_by_default_only_with_respect_retry_after(): void
    {
        $with = new RetryAfterSequenceTransport([new HttpResponse(429, '', ['retry-after' => '0']), new HttpResponse(200, 'ok')]);
        $without = new RetryAfterSequenceTransport([new HttpResponse(429, ''), new HttpResponse(200, 'ok')]);

        self::assertSame(200, (new HttpRequest('GET', 'https://x.test', $with))->retry(1, 0)->respectRetryAfter()->send()->status());
        self::assertSame(429, (new HttpRequest('GET', 'https://x.test', $without))->retry(1, 0)->send()->status());
    }

    public function test_retry_after_is_ignored_unless_enabled(): void
    {
        $backoffCalls = 0;
        $transport = new RetryAfterSequenceTransport([
            new HttpResponse(503, '', ['retry-after' => '0']),
            new HttpResponse(200, 'ok'),
        ]);

        (new HttpRequest('GET', 'https://x.test', $transport))->retry(1)->backoff($this->countingBackoff($backoffCalls))->send();

        self::assertSame(1, $backoffCalls);
    }

    private function countingBackoff(int &$calls): Backoff
    {
        return Backoff::exponential(baseMs: 4, maxMs: 4, jitter: true, random: static function (int $min, int $max) use (&$calls): int {
            $calls++;

            return 0;
        });
    }
}
