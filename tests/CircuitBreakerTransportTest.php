<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\HttpClient\CircuitBreakerTransport;
use EzPhp\HttpClient\CircuitOpenException;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Transport that answers from a script and counts calls.
 */
final class CircuitScriptedTransport implements TransportInterface
{
    public int $calls = 0;

    /** @var list<HttpResponse|HttpClientException> */
    public array $script = [];

    public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
    {
        $this->calls++;
        $next = array_shift($this->script) ?? new HttpResponse(200, 'ok');

        if ($next instanceof HttpClientException) {
            throw $next;
        }

        return $next;
    }
}

#[CoversClass(CircuitBreakerTransport::class)]
#[UsesClass(CircuitOpenException::class)]
#[UsesClass(HttpResponse::class)]
final class CircuitBreakerTransportTest extends TestCase
{
    private int $now = 1_000_000;

    private CircuitScriptedTransport $inner;

    private ArrayDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();

        ArrayLock::reset();
        $this->inner = new CircuitScriptedTransport();
        $this->cache = new ArrayDriver();
    }

    protected function tearDown(): void
    {
        ArrayLock::reset();

        parent::tearDown();
    }

    private function breaker(int $threshold = 3, int $open = 30, int $window = 60): CircuitBreakerTransport
    {
        return new CircuitBreakerTransport(
            $this->inner,
            $this->cache,
            failureThreshold: $threshold,
            openSeconds: $open,
            failureWindowSeconds: $window,
            clock: fn (): int => $this->now,
        );
    }

    private function call(CircuitBreakerTransport $breaker, string $url = 'https://api.test/x'): HttpResponse
    {
        return $breaker->send('GET', $url, [], '');
    }

    private function queueFailures(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->inner->script[] = new HttpResponse(503, 'down');
        }
    }

    public function test_a_healthy_service_passes_through(): void
    {
        $breaker = $this->breaker();

        self::assertSame(200, $this->call($breaker)->status());
        self::assertSame(1, $this->inner->calls);
    }

    public function test_the_circuit_opens_after_the_threshold_and_then_fails_fast(): void
    {
        $breaker = $this->breaker(threshold: 3);
        $this->queueFailures(3);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(503, $this->call($breaker)->status());
        }

        try {
            $this->call($breaker);
            self::fail('the circuit should be open');
        } catch (CircuitOpenException $e) {
            self::assertSame('api.test', $e->circuit);
            self::assertSame(30, $e->retryAfterSeconds);
        }

        self::assertSame(3, $this->inner->calls, 'the open circuit must not reach the service');
    }

    public function test_transport_exceptions_count_as_failures(): void
    {
        $breaker = $this->breaker(threshold: 2);
        $this->inner->script = [new HttpClientException('dns'), new HttpClientException('dns')];

        foreach ([1, 2] as $ignored) {
            try {
                $this->call($breaker);
            } catch (HttpClientException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(CircuitOpenException::class);
        $this->call($breaker);
    }

    public function test_client_errors_are_not_failures(): void
    {
        $breaker = $this->breaker(threshold: 2);

        for ($i = 0; $i < 5; $i++) {
            $this->inner->script[] = new HttpResponse(404, '');
            self::assertSame(404, $this->call($breaker)->status());
        }

        self::assertSame(200, $this->call($breaker)->status());
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $breaker = $this->breaker(threshold: 3);
        $this->queueFailures(2);
        $this->call($breaker);
        $this->call($breaker);
        $this->call($breaker); // 200: resets

        $this->queueFailures(2);
        $this->call($breaker);
        $this->call($breaker);

        self::assertSame(200, $this->call($breaker)->status(), 'only 2 consecutive failures since the reset — still closed');
    }

    public function test_old_failures_fall_out_of_the_window(): void
    {
        $breaker = $this->breaker(threshold: 3, window: 60);
        $this->queueFailures(2);
        $this->call($breaker);
        $this->call($breaker);

        $this->now += 61;
        $this->queueFailures(1);
        $this->call($breaker); // window restarted: this is failure #1, not #3

        self::assertSame(200, $this->call($breaker)->status());
    }

    public function test_after_the_open_period_one_probe_is_allowed_and_a_success_closes_the_circuit(): void
    {
        $breaker = $this->breaker(threshold: 2, open: 30);
        $this->queueFailures(2);
        $this->call($breaker);
        $this->call($breaker);

        $this->now += 30;

        self::assertSame(200, $this->call($breaker)->status(), 'the probe');
        self::assertSame(200, $this->call($breaker)->status(), 'closed again');
        self::assertSame(4, $this->inner->calls);
    }

    public function test_a_failed_probe_reopens_the_circuit_for_another_period(): void
    {
        $breaker = $this->breaker(threshold: 2, open: 30);
        $this->queueFailures(3);
        $this->call($breaker);
        $this->call($breaker);

        $this->now += 30;
        self::assertSame(503, $this->call($breaker)->status(), 'the probe fails');

        $this->now += 10;

        try {
            $this->call($breaker);
            self::fail('reopened');
        } catch (CircuitOpenException $e) {
            self::assertSame(20, $e->retryAfterSeconds);
        }

        $this->now += 20;
        self::assertSame(200, $this->call($breaker)->status(), 'next probe after another full period');
    }

    public function test_only_one_caller_probes_at_a_time(): void
    {
        $breaker = $this->breaker(threshold: 1, open: 30);
        $this->queueFailures(1);
        $this->call($breaker);
        $this->now += 30;

        // Another process holds the probe lock.
        self::assertTrue($this->cache->lock('http-client:circuit:api.test:probe', 30)->acquire());

        $this->expectException(CircuitOpenException::class);
        $this->call($breaker);
    }

    public function test_circuits_are_per_host(): void
    {
        $breaker = $this->breaker(threshold: 1);
        $this->queueFailures(1);
        $this->call($breaker, 'https://flaky.test/a');

        try {
            $this->call($breaker, 'https://flaky.test/b');
            self::fail('same host, same circuit');
        } catch (CircuitOpenException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(200, $this->call($breaker, 'https://healthy.test/a')->status());
    }

    public function test_custom_failure_classification_and_key(): void
    {
        $breaker = new CircuitBreakerTransport(
            $this->inner,
            $this->cache,
            failureThreshold: 1,
            isFailure: static fn (HttpResponse $r): bool => $r->status() === 429,
            keyResolver: static fn (string $url): string => 'shared',
            clock: fn (): int => $this->now,
        );
        $this->inner->script = [new HttpResponse(429, '')];

        $this->call($breaker, 'https://a.test/');

        try {
            $this->call($breaker, 'https://b.test/');
            self::fail('one shared circuit named "shared"');
        } catch (CircuitOpenException $e) {
            self::assertSame('shared', $e->circuit);
        }
    }

    public function test_it_is_an_http_client_exception_for_existing_catch_blocks(): void
    {
        self::assertInstanceOf(HttpClientException::class, new CircuitOpenException('x', 1));
    }

    public function test_the_default_clock_and_key_resolver_work_without_injection(): void
    {
        $breaker = new CircuitBreakerTransport($this->inner, $this->cache);

        self::assertSame(200, $breaker->send('GET', 'HTTPS://API.Test/x', [], '')->status());
        self::assertSame(200, $breaker->send('GET', 'not a url', [], '')->status());
    }
}
