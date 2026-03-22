<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Transport spy that records received headers.
 */
final class MiddlewareTransportSpy implements TransportInterface
{
    /**
     * @var array<string, string>
     */
    public array $receivedHeaders = [];

    public ?HttpResponse $stubbedResponse = null;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->receivedHeaders = $headers;

        return $this->stubbedResponse ?? new HttpResponse(200, 'ok');
    }
}

/**
 * Class MiddlewareTest
 *
 * Tests HttpRequest::withMiddleware().
 *
 * @package Tests
 */
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(FakeTransport::class)]
#[UsesClass(Http::class)]
final class MiddlewareTest extends TestCase
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
    public function test_middleware_intercepts_response(): void
    {
        Http::fake(['*' => Http::response(['original' => true], 200)]);

        $intercepted = null;

        Http::get('https://api.example.com')
            ->withMiddleware(function (\Closure $next) use (&$intercepted): HttpResponse {
                $response = $next();
                $intercepted = $response->json();

                return $response;
            })
            ->send();

        $this->assertSame(['original' => true], $intercepted);
    }

    /**
     * @return void
     */
    public function test_middleware_can_replace_response(): void
    {
        Http::fake(['*' => Http::response(['original' => true], 200)]);

        $response = Http::get('https://api.example.com')
            ->withMiddleware(function (\Closure $next): HttpResponse {
                $next(); // call but discard
                return new HttpResponse(418, 'teapot');
            })
            ->send();

        $this->assertSame(418, $response->status());
        $this->assertSame('teapot', $response->body());
    }

    /**
     * @return void
     */
    public function test_middleware_can_short_circuit_without_calling_next(): void
    {
        $spy = new MiddlewareTransportSpy();
        $client = new HttpClient($spy);

        $response = $client->get('https://api.example.com')
            ->withMiddleware(function (\Closure $next): HttpResponse {
                // Don't call $next() — return early
                return new HttpResponse(503, 'short-circuit');
            })
            ->send();

        $this->assertSame(503, $response->status());
        // Transport should never have been called
        $this->assertEmpty($spy->receivedHeaders);
    }

    /**
     * @return void
     */
    public function test_middleware_runs_in_order(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $log = [];

        Http::get('https://api.example.com')
            ->withMiddleware(function (\Closure $next) use (&$log): HttpResponse {
                $log[] = 'first-before';
                $r = $next();
                $log[] = 'first-after';
                return $r;
            })
            ->withMiddleware(function (\Closure $next) use (&$log): HttpResponse {
                $log[] = 'second-before';
                $r = $next();
                $log[] = 'second-after';
                return $r;
            })
            ->send();

        $this->assertSame(['first-before', 'second-before', 'second-after', 'first-after'], $log);
    }

    /**
     * @return void
     */
    public function test_multiple_middleware_chain_all_run(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $calls = 0;

        Http::get('https://api.example.com')
            ->withMiddleware(function (\Closure $next) use (&$calls): HttpResponse {
                $calls++;
                return $next();
            })
            ->withMiddleware(function (\Closure $next) use (&$calls): HttpResponse {
                $calls++;
                return $next();
            })
            ->send();

        $this->assertSame(2, $calls);
    }

    /**
     * @return void
     */
    public function test_with_middleware_is_immutable(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $calls = 0;

        $base = Http::get('https://api.example.com');
        $withM = $base->withMiddleware(function (\Closure $next) use (&$calls): HttpResponse {
            $calls++;
            return $next();
        });

        $base->send();
        $this->assertSame(0, $calls); // base request has no middleware

        $withM->send();
        $this->assertSame(1, $calls); // clone has middleware
    }

    /**
     * @return void
     */
    public function test_middleware_combines_with_retry(): void
    {
        $attempt = 0;
        $transport = new class ($attempt) implements TransportInterface {
            public function __construct(private int &$count)
            {
            }

            /** @param array<string, string> $headers */
            public function send(string $method, string $url, array $headers, string $body): HttpResponse
            {
                $this->count++;
                return new HttpResponse($this->count < 2 ? 500 : 200, '');
            }
        };

        $middlewareCalled = 0;

        $client = new HttpClient($transport);
        $response = $client->get('https://api.example.com')
            ->retry(1, 0)
            ->withMiddleware(function (\Closure $next) use (&$middlewareCalled): HttpResponse {
                $middlewareCalled++;
                return $next();
            })
            ->send();

        $this->assertSame(200, $response->status());
        // Retry loop is outside the middleware pipeline — middleware runs on each attempt.
        $this->assertSame(2, $middlewareCalled);
    }
}
