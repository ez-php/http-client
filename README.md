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

$fake = new FakeTransport(new HttpResponse(200, '{"id":1}', []));
Http::setClient(new HttpClient($fake));

// Act — no real network calls
$data = Http::get('https://api.example.com/users/1')->json();

Http::resetClient();
```

## Error handling

`HttpClientException` is thrown only on **transport failures** (cURL error, DNS failure, empty URL). HTTP 4xx/5xx responses are returned as normal `HttpResponse` objects — check `ok()` or `status()`.

## Classes

| Class | Description |
|---|---|
| `TransportInterface` | I/O seam: `send(method, url, headers, body): HttpResponse` |
| `CurlTransport` | cURL implementation — all `curl_*` calls are isolated here |
| `FakeTransport` | Test double that returns pre-configured `HttpResponse` objects |
| `HttpClient` | Entry point; factory methods (`get`, `post`, `put`, `patch`, `delete`) returning `HttpRequest` |
| `HttpRequest` | Fluent builder; clone-based withers; dispatch shortcuts (`send`, `json`, `body`, `status`) |
| `HttpResponse` | Immutable value object: `status()`, `body()`, `json()`, `header()`, `ok()` |
| `HttpClientException` | Thrown on transport-level failures (not on 4xx/5xx) |
| `Http` | Static façade backed by a managed `HttpClient` singleton |
| `HttpClientServiceProvider` | Binds transport + client; wires static façade; eager boot |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
