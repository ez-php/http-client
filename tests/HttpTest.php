<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Transport spy that records method and URL from the last send() call.
 */
final class HttpFacadeTransportSpy implements TransportInterface
{
    public ?string $method = null;

    public ?string $url = null;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->method = $method;
        $this->url = $url;

        return new HttpResponse(200, '{"ok":true}');
    }
}

/**
 * Null transport that always returns 200 with an empty body.
 */
final class HttpFacadeNullTransport implements TransportInterface
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
 * Class HttpTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(Http::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
final class HttpTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::resetClient();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    // ─── Client management ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_client_creates_instance_lazily(): void
    {
        $client = Http::getClient();

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    /**
     * @return void
     */
    public function test_set_client_replaces_instance(): void
    {
        $custom = new HttpClient(new HttpFacadeNullTransport());
        Http::setClient($custom);

        $this->assertSame($custom, Http::getClient());
    }

    /**
     * @return void
     */
    public function test_reset_client_clears_instance(): void
    {
        $first = Http::getClient();
        Http::resetClient();
        $second = Http::getClient();

        $this->assertNotSame($first, $second);
    }

    // ─── Facade methods return HttpRequest ───────────────────────────────────

    /**
     * @return void
     */
    public function test_get_returns_http_request(): void
    {
        Http::setClient(new HttpClient(new HttpFacadeNullTransport()));

        $this->assertInstanceOf(HttpRequest::class, Http::get('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_post_returns_http_request(): void
    {
        Http::setClient(new HttpClient(new HttpFacadeNullTransport()));

        $this->assertInstanceOf(HttpRequest::class, Http::post('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_put_returns_http_request(): void
    {
        Http::setClient(new HttpClient(new HttpFacadeNullTransport()));

        $this->assertInstanceOf(HttpRequest::class, Http::put('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_patch_returns_http_request(): void
    {
        Http::setClient(new HttpClient(new HttpFacadeNullTransport()));

        $this->assertInstanceOf(HttpRequest::class, Http::patch('https://example.com'));
    }

    /**
     * @return void
     */
    public function test_delete_returns_http_request(): void
    {
        Http::setClient(new HttpClient(new HttpFacadeNullTransport()));

        $this->assertInstanceOf(HttpRequest::class, Http::delete('https://example.com'));
    }

    // ─── Method delegation ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_delegates_correct_method(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::get('https://example.com')->send();

        $this->assertSame('GET', $spy->method);
    }

    /**
     * @return void
     */
    public function test_post_delegates_correct_method(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::post('https://example.com')->send();

        $this->assertSame('POST', $spy->method);
    }

    /**
     * @return void
     */
    public function test_put_delegates_correct_method(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::put('https://example.com')->send();

        $this->assertSame('PUT', $spy->method);
    }

    /**
     * @return void
     */
    public function test_patch_delegates_correct_method(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::patch('https://example.com')->send();

        $this->assertSame('PATCH', $spy->method);
    }

    /**
     * @return void
     */
    public function test_delete_delegates_correct_method(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::delete('https://example.com')->send();

        $this->assertSame('DELETE', $spy->method);
    }

    /**
     * @return void
     */
    public function test_url_is_forwarded(): void
    {
        $spy = new HttpFacadeTransportSpy();
        Http::setClient(new HttpClient($spy));

        Http::get('https://api.example.com/users')->send();

        $this->assertSame('https://api.example.com/users', $spy->url);
    }
}
