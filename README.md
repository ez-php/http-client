# ez-php/http-client

HTTP client module for the [ez-php framework](https://github.com/ez-php/framework) — fluent cURL-based client for outgoing HTTP requests, a static `Http` façade, and a `FakeTransport` for testing.

[![CI](https://github.com/ez-php/http-client/actions/workflows/ci.yml/badge.svg)](https://github.com/ez-php/http-client/actions/workflows/ci.yml)

## Requirements

- PHP 8.5+
- ext-curl
- ez-php/framework 0.*

## Installation

```bash
composer require ez-php/http-client
```

## Setup

Register the service provider:

```php
$app->register(\EzPhp\HttpClient\HttpClientServiceProvider::class);
```

## Usage

```php
use EzPhp\HttpClient\Http;

// Static façade (wired by provider)
$response = Http::get('https://api.example.com/users')->send();
$response = Http::post('https://api.example.com/users')
    ->withJson(['name' => 'Alice'])
    ->send();

echo $response->status();    // 200
echo $response->body();      // raw response body
$data = $response->json();   // decoded JSON array
$ok   = $response->ok();     // true for 2xx

// Convenience shortcuts
$data   = Http::get('https://api.example.com/users')->json();
$body   = Http::get('https://api.example.com/data')->body();
$status = Http::delete('https://api.example.com/users/1')->status();
```

### Fluent request builder

```php
Http::post('https://api.example.com/upload')
    ->withHeader('Authorization', 'Bearer token123')
    ->withJson(['name' => 'Alice'])
    ->send();

Http::post('https://api.example.com/form')
    ->withForm(['field' => 'value'])
    ->send();
```

### Per-request timeout

Requests time out after 30 seconds by default. `withTimeout()` overrides that for a
single request — useful for health checks that should fail fast, or for endpoints
known to be slow:

```php
Http::get('https://api.example.com/health')
    ->withTimeout(2)
    ->send();
```

The timeout bounds each individual attempt. Combined with `retry()`, a request with
`withTimeout(3)->retry(2)` can still take up to roughly 9 seconds in total.

`PooledRequest::withTimeout()` does the same for concurrent requests, where each
handle carries its own timeout.

### Streaming responses

`stream()` returns as soon as the response headers arrive; the body is read while you iterate:

```php
$stream = Http::post('https://api.example.com/export')
    ->withJson(['format' => 'ndjson'])
    ->withIdleTimeout(60)
    ->stream();

if (!$stream->ok()) {
    throw new RuntimeException($stream->body());
}

foreach ($stream as $chunk) {
    // raw bytes as received — a line may span several chunks
}
```

Streams have no total timeout; `withIdleTimeout()` (default 30 s) fails the transfer only when no data arrives for that long. `retry()` and `withMiddleware()` cannot be combined with `stream()`. Stopping early — `break`, `$stream->close()`, or dropping the stream or its iterator — closes the connection.

For `text/event-stream` bodies, `SseDecoder` yields complete events:

```php
use EzPhp\HttpClient\Sse\SseDecoder;

foreach (SseDecoder::decode($stream) as $message) {
    echo $message->event(), ': ', $message->data(), PHP_EOL;
}
```

### Injected client (without façade)

```php
use EzPhp\HttpClient\HttpClient;

$client = $app->make(HttpClient::class);
$response = $client->get('https://api.example.com')->send();
```

## Testing

Replace the transport with a fake in tests:

```php
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;

$fake = new FakeTransport([
    'https://api.example.com/*' => new HttpResponse(200, '{"id":1}', []),
]);
Http::setClient(new HttpClient($fake));

// Act — no real network calls
$data = Http::get('https://api.example.com/users/1')->json();

Http::resetClient();
```

Streamed requests use the same fake. `HttpStream::fake()` takes the chunks; a `Throwable` in the list is thrown at that position:

```php
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;

Http::fake([
    'https://api.example.com/*' => HttpStream::fake(["data: 1\n\n", new HttpStreamException('reset')]),
]);
```

A plain `Http::response()` fixture also works for `stream()` and arrives as a single chunk.

## Error handling

`HttpClientException` is thrown only on **transport failures** (cURL error, DNS failure, empty URL). HTTP 4xx/5xx responses are returned as normal `HttpResponse` / `HttpStream` objects — check `ok()` or `status()`.

For streams, failures before the headers throw `HttpClientException` from `stream()`; failures while reading the body (idle timeout, connection lost) throw `HttpStreamException`, a subclass, from the iterator.

## Classes

| Class | Description |
|---|---|
| `TransportInterface` | I/O seam: `send(method, url, headers, body, timeoutSeconds = null): HttpResponse` |
| `StreamingTransportInterface` | Extends `TransportInterface` with `stream(method, url, headers, body, idleTimeoutSeconds): HttpStream` |
| `CurlTransport` | cURL implementation of both — all `curl_*` calls are isolated here and in `CurlStreamHandle` |
| `FakeTransport` | Test double that returns pre-configured `HttpResponse` / `HttpStream` objects |
| `HttpStream` | Streamed response: `status()`, `headers()`, `ok()`, chunk iteration, `body()`, `close()`, `fake()` |
| `HttpStreamException` | Thrown while reading a stream body (idle timeout, connection lost) |
| `Sse\SseDecoder` / `Sse\SseMessage` | Decode `text/event-stream` chunks into events |
| `HttpClient` | Entry point; factory methods (`get`, `post`, `put`, `patch`, `delete`) returning `HttpRequest` |
| `HttpRequest` | Fluent builder; clone-based withers; dispatch shortcuts (`send`, `json`, `body`, `status`) |
| `HttpResponse` | Immutable value object: `status()`, `body()`, `json()`, `header()`, `ok()` |
| `HttpClientException` | Thrown on transport-level failures (not on 4xx/5xx) |
| `Http` | Static façade backed by a managed `HttpClient` singleton |
| `HttpClientServiceProvider` | Binds transport + client; wires static façade; eager boot |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
