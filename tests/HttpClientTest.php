<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Transport spy that records the HTTP method of the last send() call.
 */
final class HttpClientTransportSpy implements TransportInterface
{
    public ?string $method = null;

    public bool $wasCalled = false;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->method = $method;
        $this->wasCalled = true;

        return new HttpResponse(200, '');
    }
}

/**
 * Null transport that always returns 200 with an empty body.
 */
final class HttpClientNullTransport implements TransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        return new HttpResponse(200, '');
    }
}

/**
 * Class HttpClientTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpClient::class)]
#[UsesClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
final class HttpClientTest extends TestCase
{
    // ─── Factory methods return HttpRequest ───────────────────────────────────

    /**
     * @return void
     */
    public function test_get_returns_http_request(): void
    {
        $client = new HttpClient(new HttpClientNullTransport());

        $this->assertInstanceOf(HttpRequest::class, $client->get('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_post_returns_http_request(): void
    {
        $client = new HttpClient(new HttpClientNullTransport());

        $this->assertInstanceOf(HttpRequest::class, $client->post('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_put_returns_http_request(): void
    {
        $client = new HttpClient(new HttpClientNullTransport());

        $this->assertInstanceOf(HttpRequest::class, $client->put('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_patch_returns_http_request(): void
    {
        $client = new HttpClient(new HttpClientNullTransport());

        $this->assertInstanceOf(HttpRequest::class, $client->patch('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_delete_returns_http_request(): void
    {
        $client = new HttpClient(new HttpClientNullTransport());

        $this->assertInstanceOf(HttpRequest::class, $client->delete('https://example.com'));
    }

    // ─── Correct method string ────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_produces_get_method(): void
    {
        $spy = new HttpClientTransportSpy();
        (new HttpClient($spy))->get('https://example.com')->send();

        $this->assertSame('GET', $spy->method);
    }

    /**
     * @return void
     */
    public function test_post_produces_post_method(): void
    {
        $spy = new HttpClientTransportSpy();
        (new HttpClient($spy))->post('https://example.com')->send();

        $this->assertSame('POST', $spy->method);
    }

    /**
     * @return void
     */
    public function test_delete_produces_delete_method(): void
    {
        $spy = new HttpClientTransportSpy();
        (new HttpClient($spy))->delete('https://example.com')->send();

        $this->assertSame('DELETE', $spy->method);
    }

    // ─── Transport is forwarded ───────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_transport_is_forwarded_to_request(): void
    {
        $spy = new HttpClientTransportSpy();
        (new HttpClient($spy))->get('https://example.com')->send();

        $this->assertTrue($spy->wasCalled);
    }
}
