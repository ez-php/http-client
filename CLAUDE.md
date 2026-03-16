# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "0.*"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3310** | **6381** |

Only set a port for services the module actually uses. Modules without external services need no port config.

---

# Package: ez-php/http-client

Fluent cURL HTTP client for outgoing requests.

---

## Source Structure

```
src/
├── TransportInterface.php         — I/O seam: send(method, url, headers, body) → HttpResponse
├── CurlTransport.php              — cURL implementation; all curl_* calls are isolated here
├── HttpClient.php                 — Entry point; factory methods returning a configured HttpRequest
├── HttpRequest.php                — Fluent builder for a pending request; dispatches via transport
├── HttpResponse.php               — Immutable value object wrapping the response (status, body, headers)
├── HttpClientException.php        — Thrown on transport failures (not on 4xx/5xx responses)
├── Http.php                       — Static façade backed by a managed HttpClient singleton
└── HttpClientServiceProvider.php  — Binds TransportInterface + HttpClient; wires static façade; eager boot

tests/
├── TestCase.php                          — Base PHPUnit test case
├── HttpClientTest.php                    — Covers HttpClient factory methods using a fake transport
├── HttpRequestTest.php                   — Covers HttpRequest builder: withHeaders, withJson, withForm, send shortcuts
├── HttpResponseTest.php                  — Covers HttpResponse: status, body, json, header, ok
├── HttpTest.php                          — Covers Http façade: setClient, resetClient, lazy default client
└── HttpClientServiceProviderTest.php     — Covers provider registration and transport rebinding
```

---

## Key Classes and Responsibilities

### TransportInterface (`src/TransportInterface.php`)

The single I/O seam for the entire package. All network I/O is behind this interface.

```php
public function send(string $method, string $url, array $headers, string $body): HttpResponse;
```

Throws `HttpClientException` on transport-level failures (network error, cURL init failure, empty URL). **HTTP error responses (4xx, 5xx) are not exceptions** — they are valid `HttpResponse` objects.

Replace with a test double in unit tests to avoid real network calls.

---

### CurlTransport (`src/CurlTransport.php`)

The only class in this package that calls any `curl_*` function. All cURL logic is encapsulated here.

Key behaviours:
- `CURLOPT_RETURNTRANSFER true` + `CURLOPT_HEADER true` — response includes raw headers prepended to body
- `CURLOPT_TIMEOUT` — fixed at 30 seconds (`TIMEOUT_SECONDS` constant)
- `CURLOPT_FOLLOWLOCATION true` — follows redirects automatically
- `CURLOPT_CUSTOMREQUEST` — used for all verbs, including GET with a body
- Headers split via `CURLINFO_HEADER_SIZE`; on redirects only the **last** header block is kept
- Response headers normalised to **lowercase** on parse
- Throws `HttpClientException` if `curl_init()` fails, URL is empty, or `curl_exec()` returns `false`

---

### HttpClient (`src/HttpClient.php`)

Entry point for application code (or use the `Http` façade). Takes a `TransportInterface` in its constructor.

| Method | Returns |
|---|---|
| `get(string $url)` | `HttpRequest` configured for GET |
| `post(string $url)` | `HttpRequest` configured for POST |
| `put(string $url)` | `HttpRequest` configured for PUT |
| `patch(string $url)` | `HttpRequest` configured for PATCH |
| `delete(string $url)` | `HttpRequest` configured for DELETE |

---

### HttpRequest (`src/HttpRequest.php`)

Fluent **builder** for a pending request. Each wither returns a clone — the original is not mutated.

**Builder methods:**

| Method | Effect |
|---|---|
| `withHeaders(array)` | Merges headers into existing set |
| `withHeader(name, value)` | Adds/replaces a single header |
| `withBody(string)` | Sets a raw string body |
| `withJson(array)` | JSON-encodes data; sets `Content-Type: application/json` |
| `withForm(array)` | `http_build_query()` encodes data; sets `Content-Type: application/x-www-form-urlencoded` |

**Dispatch shortcuts** (each calls `send()` internally):

| Method | Returns |
|---|---|
| `send()` | `HttpResponse` — full response object |
| `json()` | `mixed` — decoded JSON body |
| `body()` | `string` — raw response body |
| `status()` | `int` — HTTP status code |

All dispatch methods throw `HttpClientException` on transport failure.

---

### HttpResponse (`src/HttpResponse.php`)

Immutable value object. Constructed only by `TransportInterface` implementations.

| Method | Returns | Notes |
|---|---|---|
| `status()` | `int` | HTTP status code |
| `body()` | `string` | Raw response body |
| `json()` | `mixed` | `json_decode($body, true)`; `null` on empty or invalid JSON |
| `header(name, default)` | `string` | Case-insensitive lookup; headers stored lowercase |
| `ok()` | `bool` | `true` for 2xx status codes |

---

### HttpClientException (`src/HttpClientException.php`)

Extends `RuntimeException`. Thrown exclusively for **transport-layer failures**: cURL init failure, DNS/network error, empty URL or method. **Not thrown for 4xx or 5xx responses** — those are returned as valid `HttpResponse` objects and must be checked via `ok()` or `status()`.

---

### Http (`src/Http.php`)

Static façade. Delegates to the managed `HttpClient` singleton.

| Method | Delegates to |
|---|---|
| `Http::get($url)` | `HttpClient::get()` |
| `Http::post($url)` | `HttpClient::post()` |
| `Http::put($url)` | `HttpClient::put()` |
| `Http::patch($url)` | `HttpClient::patch()` |
| `Http::delete($url)` | `HttpClient::delete()` |
| `Http::setClient($client)` | Replaces the singleton (used by provider and tests) |
| `Http::getClient()` | Returns singleton; lazily creates `HttpClient(new CurlTransport())` if none set |
| `Http::resetClient()` | Sets singleton to `null` (tests must call in `setUp`/`tearDown`) |

Without `HttpClientServiceProvider`, the first `Http::get()` call creates a default `CurlTransport`-backed client. With the provider, the container-managed instance is wired via `Http::setClient()`.

---

### HttpClientServiceProvider (`src/HttpClientServiceProvider.php`)

- **`register()`** — Binds `TransportInterface::class → CurlTransport::class`; binds `HttpClient::class` as a factory that resolves the transport, creates the client, and calls `Http::setClient()`.
- **`boot()`** — Eagerly calls `$app->make(HttpClient::class)` so the static façade is wired before any code calls `Http::get()` etc.

To replace the transport (e.g. in tests or for a custom implementation), rebind `TransportInterface` before this provider's `register()` runs:

```php
$app->bind(TransportInterface::class, MyCustomTransport::class);
```

---

## Design Decisions and Constraints

- **`TransportInterface` is the only seam** — All cURL calls live in `CurlTransport`. Nothing else touches cURL. This makes the entire request-building and response-handling stack testable without network access by swapping in a `TransportInterface` test double.
- **4xx/5xx are not exceptions** — HTTP error responses are valid protocol responses. Throwing on them would force callers to use `try/catch` for normal control flow. Check `ok()` or `status()` explicitly. `HttpClientException` is reserved for transport failures where no response could be received at all.
- **Fluent builder returns clones** — `withHeaders()`, `withBody()`, etc. return new `HttpRequest` instances. This allows a single base request to be forked without side effects.
- **`Http` lazy-creates a default client** — `Http::getClient()` creates `HttpClient(new CurlTransport())` if no client is set. This means the façade is usable without registering the service provider, at the cost of no container integration. The provider replaces this with a container-managed instance on boot.
- **`HttpClientServiceProvider` boots eagerly** — Same rationale as `EventServiceProvider`: the static façade must be wired before application code calls `Http::get()`. Without eager resolution, a race with provider boot order would cause the façade to fall back to an unmanaged default client.
- **Redirect header handling** — On redirects, `CurlTransport` discards all intermediate header blocks and keeps only the last one (the final response). This avoids leaking `Location:` headers from intermediate responses into the caller's view.
- **30-second timeout** — `TIMEOUT_SECONDS` is a class constant in `CurlTransport`. If per-request timeout configuration is needed, it belongs on `HttpRequest` and must be passed through `TransportInterface::send()`.

---

## Testing Approach

- **No real network calls in unit tests** — Implement a `TransportInterface` test double (anonymous class or stub) that returns a hard-coded `HttpResponse`. Pass it to `HttpClient` directly or via `Http::setClient()`.
- **Always call `Http::resetClient()`** in `setUp()` and `tearDown()` of any test that touches the `Http` façade. Omitting this leaks a client instance between tests.
- **`CurlTransport` is not unit-tested** — Its behaviour depends on live network access. Cover it via integration tests in a Docker environment where outbound connections are available.
- **`HttpRequest` builder tests** — Construct with a test-double transport, chain builder methods, call `send()`, assert the transport received the expected method/URL/headers/body and that the returned `HttpResponse` is passed through correctly.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Incoming HTTP requests (server-side) | `ez-php/http` (`Request`, `RequestFactory`) |
| Response caching | `ez-php/cache` or application layer |
| Authentication for outgoing requests (OAuth, API key injection) | Application layer (configure via `withHeader()`) |
| Retry logic / exponential backoff | Application layer or a decorator wrapping `TransportInterface` |
| Per-request timeout configuration | Future extension to `HttpRequest` and `TransportInterface::send()` |
| Async / concurrent requests | Out of scope; requires a different I/O model (fibers, ReactPHP, etc.) |
| Streaming responses | Out of scope |
| Multipart file uploads | Application layer using `withBody()` with a manually constructed multipart body |

