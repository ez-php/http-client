# Changelog

All notable changes to `ez-php/http-client` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `HttpClient` — fluent cURL-based HTTP client supporting GET, POST, PUT, PATCH, DELETE, and HEAD requests
- Clone-based withers — `withHeader()`, `withBaseUrl()`, `withTimeout()`, `withToken()`, `withBasicAuth()` each return a new immutable client instance
- JSON request/response handling — automatic `Content-Type: application/json` and response body decoding
- `HttpResponse` — value object with `status()`, `body()`, `json()`, and `header()` accessors
- `Http` static facade — instant client access without container setup
- `HttpClientServiceProvider` — binds the client and registers the `Http` facade alias
- `HttpClientException` for cURL errors and unexpected response codes
