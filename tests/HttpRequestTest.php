<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Transport spy that records every send() invocation.
 */
final class HttpRequestTransportSpy implements TransportInterface
{
    public ?string $method = null;

    public ?string $url = null;

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    /**
     * HttpRequestTransportSpy Constructor
     *
     * @param string $responseBody
     */
    public function __construct(private readonly string $responseBody = '{"ok":true}')
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        return new HttpResponse(200, $this->responseBody);
    }
}

/**
 * Class HttpRequestTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
final class HttpRequestTest extends TestCase
{
    // ─── HTTP method ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_uses_correct_method_get(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))->send();

        $this->assertSame('GET', $spy->method);
    }

    /**
     * @return void
     */
    public function test_uses_correct_method_post(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))->send();

        $this->assertSame('POST', $spy->method);
    }

    /**
     * @return void
     */
    public function test_uses_correct_method_put(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('PUT', 'https://example.com', $spy))->send();

        $this->assertSame('PUT', $spy->method);
    }

    /**
     * @return void
     */
    public function test_uses_correct_method_patch(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('PATCH', 'https://example.com', $spy))->send();

        $this->assertSame('PATCH', $spy->method);
    }

    /**
     * @return void
     */
    public function test_uses_correct_method_delete(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('DELETE', 'https://example.com', $spy))->send();

        $this->assertSame('DELETE', $spy->method);
    }

    // ─── URL ─────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_url_is_forwarded_to_transport(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://api.example.com/users', $spy))->send();

        $this->assertSame('https://api.example.com/users', $spy->url);
    }

    // ─── withHeaders ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_with_headers_merges_headers(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))
            ->withHeaders(['Authorization' => 'Bearer token', 'Accept' => 'application/json'])
            ->send();

        $this->assertSame('Bearer token', $spy->headers['Authorization']);
        $this->assertSame('application/json', $spy->headers['Accept']);
    }

    /**
     * @return void
     */
    public function test_with_headers_is_immutable(): void
    {
        $spy = new HttpRequestTransportSpy();
        $original = new HttpRequest('GET', 'https://example.com', $spy);
        $modified = $original->withHeaders(['X-Foo' => 'bar']);

        $this->assertNotSame($original, $modified);
    }

    /**
     * @return void
     */
    public function test_with_header_sets_single_header(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))
            ->withHeader('X-Custom', 'value')
            ->send();

        $this->assertSame('value', $spy->headers['X-Custom']);
    }

    // ─── withBody ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_with_body_sends_raw_body(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))
            ->withBody('raw body content')
            ->send();

        $this->assertSame('raw body content', $spy->body);
    }

    // ─── withJson ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_with_json_encodes_body(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))
            ->withJson(['name' => 'Alice'])
            ->send();

        $this->assertSame('{"name":"Alice"}', $spy->body);
    }

    /**
     * @return void
     */
    public function test_with_json_sets_content_type_header(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))
            ->withJson(['name' => 'Alice'])
            ->send();

        $this->assertSame('application/json', $spy->headers['Content-Type']);
    }

    // ─── withForm ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_with_form_encodes_body(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))
            ->withForm(['email' => 'a@b.com', 'password' => 'secret'])
            ->send();

        $this->assertSame('email=a%40b.com&password=secret', $spy->body);
    }

    /**
     * @return void
     */
    public function test_with_form_sets_content_type_header(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('POST', 'https://example.com', $spy))
            ->withForm(['foo' => 'bar'])
            ->send();

        $this->assertSame('application/x-www-form-urlencoded', $spy->headers['Content-Type']);
    }

    // ─── Terminal shortcuts ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_json_shortcut_decodes_response(): void
    {
        $spy = new HttpRequestTransportSpy('{"name":"Alice"}');
        $result = (new HttpRequest('GET', 'https://example.com', $spy))->json();

        $this->assertSame(['name' => 'Alice'], $result);
    }

    /**
     * @return void
     */
    public function test_body_shortcut_returns_raw_body(): void
    {
        $spy = new HttpRequestTransportSpy('raw response');
        $result = (new HttpRequest('GET', 'https://example.com', $spy))->body();

        $this->assertSame('raw response', $result);
    }

    /**
     * @return void
     */
    public function test_status_shortcut_returns_status_code(): void
    {
        $spy = new HttpRequestTransportSpy();
        $status = (new HttpRequest('GET', 'https://example.com', $spy))->status();

        $this->assertSame(200, $status);
    }

    // ─── Defaults ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_default_body_is_empty_string(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))->send();

        $this->assertSame('', $spy->body);
    }

    /**
     * @return void
     */
    public function test_default_headers_are_empty(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))->send();

        $this->assertSame([], $spy->headers);
    }
}
