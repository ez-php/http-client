# ez-php/http-client

HTTP client module for the [ez-php framework](https://github.com/ez-php/framework) — fluent cURL-based client for making outgoing HTTP requests.

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
$http = $app->make(\EzPhp\HttpClient\Http::class);

$response = $http->get('https://api.example.com/users');
$response = $http->post('https://api.example.com/users', ['name' => 'Alice']);

echo $response->status();    // 200
echo $response->body();      // raw response body
$data = $response->json();   // decoded JSON
```

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
