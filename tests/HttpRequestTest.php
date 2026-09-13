<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\StreamingTransportInterface;
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

    public ?int $timeoutSeconds = null;

    public int $callCount = 0;

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
    public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->callCount++;

        return new HttpResponse(200, $this->responseBody);
    }
}

/**
 * Streaming transport spy that records every stream() invocation.
 */
final class HttpRequestStreamingTransportSpy implements StreamingTransportInterface
{
    public ?string $method = null;

    public ?string $url = null;

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public ?int $idleTimeoutSeconds = null;

    public ?HttpStream $returned = null;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
    {
        return new HttpResponse(200, '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function stream(string $method, string $url, array $headers, string $body, int $idleTimeoutSeconds): HttpStream
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
        $this->returned = HttpStream::fake(['ok']);

        return $this->returned;
    }
}

/**
 * Class HttpRequestTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(HttpStream::class)]
#[UsesClass(HttpClientException::class)]
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

    // ─── Timeout ─────────────────────────────────────────────────────────────

    /**
     * Without withTimeout() the transport receives null and applies its own default.
     *
     * @return void
     */
    public function test_default_timeout_is_null(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))->send();

        $this->assertNull($spy->timeoutSeconds);
    }

    /**
     * @return void
     */
    public function test_with_timeout_is_passed_to_transport(): void
    {
        $spy = new HttpRequestTransportSpy();
        (new HttpRequest('GET', 'https://example.com', $spy))->withTimeout(5)->send();

        $this->assertSame(5, $spy->timeoutSeconds);
    }

    /**
     * @return void
     */
    public function test_with_timeout_returns_a_clone_and_does_not_mutate_the_original(): void
    {
        $spy = new HttpRequestTransportSpy();
        $request = new HttpRequest('GET', 'https://example.com', $spy);

        $withTimeout = $request->withTimeout(7);
        $this->assertNotSame($request, $withTimeout);

        $request->send();
        $this->assertNull($spy->timeoutSeconds);

        $withTimeout->send();
        $this->assertSame(7, $spy->timeoutSeconds);
    }

    /**
     * The timeout applies to each retry attempt, not to the sequence as a whole.
     *
     * @return void
     */
    public function test_timeout_applies_to_every_retry_attempt(): void
    {
        $spy = new HttpRequestTransportSpy();

        (new HttpRequest('GET', 'https://example.com', $spy))
            ->withTimeout(3)
            ->retry(2, 0, static fn (HttpResponse $r): bool => $r->status() === 200)
            ->send();

        $this->assertSame(3, $spy->callCount);
        $this->assertSame(3, $spy->timeoutSeconds);
    }

    // ─── stream() ─────────────────────────────────────────────────────────────

    public function test_stream_passes_method_url_headers_and_body_to_the_transport(): void
    {
        $spy = new HttpRequestStreamingTransportSpy();

        $stream = (new HttpRequest('POST', 'https://api.example.com/s', $spy))
            ->withHeaders(['Authorization' => 'Bearer t'])
            ->withJson(['stream' => true])
            ->stream();

        $this->assertSame($spy->returned, $stream);
        $this->assertSame('POST', $spy->method);
        $this->assertSame('https://api.example.com/s', $spy->url);
        $this->assertSame(['Authorization' => 'Bearer t', 'Content-Type' => 'application/json'], $spy->headers);
        $this->assertSame('{"stream":true}', $spy->body);
    }

    public function test_stream_uses_the_default_idle_timeout(): void
    {
        $spy = new HttpRequestStreamingTransportSpy();

        (new HttpRequest('GET', 'https://example.com', $spy))->stream();

        $this->assertSame(HttpRequest::DEFAULT_IDLE_TIMEOUT_SECONDS, $spy->idleTimeoutSeconds);
        $this->assertSame(30, HttpRequest::DEFAULT_IDLE_TIMEOUT_SECONDS);
    }

    public function test_with_idle_timeout_overrides_the_default_on_a_clone(): void
    {
        $spy = new HttpRequestStreamingTransportSpy();
        $original = new HttpRequest('GET', 'https://example.com', $spy);

        $original->withIdleTimeout(90)->stream();
        $this->assertSame(90, $spy->idleTimeoutSeconds);

        $original->stream();
        $this->assertSame(30, $spy->idleTimeoutSeconds);
    }

    public function test_stream_sends_a_multipart_body(): void
    {
        $spy = new HttpRequestStreamingTransportSpy();

        (new HttpRequest('POST', 'https://example.com', $spy))->attach('file', 'contents', 'a.txt', 'text/plain')->stream();

        $this->assertStringStartsWith('multipart/form-data; boundary=', $spy->headers['Content-Type']);
        $this->assertStringContainsString('filename="a.txt"', $spy->body);
    }

    public function test_stream_rejects_retry(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('stream() does not support retry()');

        (new HttpRequest('GET', 'https://example.com', new HttpRequestStreamingTransportSpy()))->retry(2)->stream();
    }

    public function test_stream_rejects_middleware(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('stream() does not support withMiddleware()');

        (new HttpRequest('GET', 'https://example.com', new HttpRequestStreamingTransportSpy()))
            ->withMiddleware(static fn (\Closure $next): HttpResponse => new HttpResponse(200, ''))
            ->stream();
    }

    public function test_stream_rejects_a_non_streaming_transport(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage(HttpRequestTransportSpy::class . ' does not support streaming');

        (new HttpRequest('GET', 'https://example.com', new HttpRequestTransportSpy()))->stream();
    }
}
