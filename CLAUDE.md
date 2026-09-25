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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
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

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/http-client

Fluent cURL HTTP client for outgoing requests.

---

## Source Structure

```
src/
├── TransportInterface.php         — I/O seam: send(method, url, headers, body) → HttpResponse
├── CurlTransport.php              — cURL implementation; all curl_* calls are isolated here
├── FakeTransport.php              — Test double: returns pre-configured HttpResponse objects; records sent requests for assertions
├── CircuitBreakerTransport.php    — TransportInterface decorator: per-host circuit (closed/open/half-open) with state in ez-php/cache (soft dependency)
├── CircuitOpenException.php       — HttpClientException thrown instead of calling an open circuit; never retried by retry()
├── Backoff.php                    — Delay strategy for retry(): constant() / exponential() with optional equal jitter
├── HttpClient.php                 — Entry point; factory methods returning a configured HttpRequest
├── HttpRequest.php                — Fluent builder for a pending request; dispatches via transport
├── HttpResponse.php               — Immutable value object wrapping the response (status, body, headers)
├── HttpClientException.php        — Thrown on transport failures (not on 4xx/5xx responses)
├── StreamingTransportInterface.php — Extends TransportInterface with stream() → HttpStream
├── CurlStreamHandle.php           — @internal: one streamed transfer in its own curl_multi; header-block detection; pump
├── HttpStream.php                 — Streamed response: status/headers up front, chunk generator, body(), close(), fake()
├── HttpStreamException.php        — Mid-body stream failure (idle timeout, connection lost); extends HttpClientException
├── Sse/
│   ├── SseDecoder.php             — WHATWG event-stream decoder over raw chunks
│   └── SseMessage.php             — Decoded event: data, event, id
├── Pool.php                       — Concurrent request pool: register multiple PooledRequests, execute all, return responses
├── PooledRequest.php              — Value object: a request + an optional key for result indexing
├── Http.php                       — Static façade backed by a managed HttpClient singleton
└── HttpClientServiceProvider.php  — Binds TransportInterface + HttpClient; wires static façade; eager boot

tests/
├── TestCase.php                          — Base PHPUnit test case
├── HttpClientTest.php                    — Covers HttpClient factory methods using a fake transport
├── HttpRequestTest.php                   — Covers HttpRequest builder: withHeaders, withJson, withForm, send shortcuts
├── HttpResponseTest.php                  — Covers HttpResponse: status, body, json, header, ok
├── HttpTest.php                          — Covers Http façade: setClient, resetClient, lazy default client
├── HttpStreamTest.php                    — HttpStream accessors, iteration, body(), close hook, fake()
├── RetryTest.php                         — retry(): default 5xx rule, custom condition, backoff() replacing the fixed sleep, CircuitOpenException never retried
├── BackoffTest.php                       — constant/exponential delays, cap, multiplier, equal-jitter bounds, injected random source
├── CircuitBreakerTransportTest.php       — closed→open→half-open→closed, failed probe, single probe under a held lock, failure window, per-host circuits, custom classification
├── CurlTransportStreamTest.php           — Real streamed transfers against a php -S loopback server
├── Support/stream-server.php             — Router for that server (one path per transfer behaviour)
├── Sse/SseDecoderTest.php                — Decoder spec cases; fixture split at every byte offset
└── HttpClientServiceProviderTest.php     — Covers provider registration and transport rebinding
```

---

## Key Classes and Responsibilities

### TransportInterface (`src/TransportInterface.php`)

The single I/O seam for the entire package. All network I/O is behind this interface.

```php
public function send(
    string $method,
    string $url,
    array $headers,
    string $body,
    ?int $timeoutSeconds = null,
): HttpResponse;
```

`$timeoutSeconds` is the total request timeout; `null` means the implementation applies its own default.

Throws `HttpClientException` on transport-level failures (network error, cURL init failure, empty URL). **HTTP error responses (4xx, 5xx) are not exceptions** — they are valid `HttpResponse` objects.

Replace with a test double in unit tests to avoid real network calls.

---

### CurlTransport (`src/CurlTransport.php`)

The only class in this package that calls any `curl_*` function. All cURL logic is encapsulated here.

Key behaviours:
- `CURLOPT_RETURNTRANSFER true` + `CURLOPT_HEADER true` — response includes raw headers prepended to body
- `CURLOPT_TIMEOUT` — the `$timeoutSeconds` argument, falling back to the public `TIMEOUT_SECONDS` constant (30) when null
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
| `withTimeout(int)` | Sets the total request timeout in seconds for this request (overrides the transport default) |

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
- **30-second default timeout, overridable per request** — `TIMEOUT_SECONDS` is a public class constant on `CurlTransport` (and `Pool`) used as the fallback. `HttpRequest::withTimeout()` / `PooledRequest::withTimeout()` override it for a single request; the value travels as the optional fifth argument of `TransportInterface::send()`. `null` means "use the transport's default", so existing callers are unaffected.
- **The timeout parameter was added to `TransportInterface`, not worked around** — This widens the interface, which breaks any external implementor at load time (PHP requires an implementation to declare every parameter the interface does, optional ones included). It was still the right call: the alternative — smuggling the timeout through a header or a transport constructor — would have put per-request state on a per-application object. All 13 in-repo implementations (2 in `src/`, 11 test doubles) were updated together.
- **`FakeTransport::getRecorded()` gained a `timeoutSeconds` key** — `FakeTransport` ships in `src/`, not `tests/`, so it is public test infrastructure and its recorded-request shape is part of the package's surface. Callers that index individual keys (`getRecorded()[0]['body']`) are unaffected; a caller asserting the whole array with `assertSame` would break. All in-repo callers — `Http::assertSent()`/`assertNotSent()` and the `ez-php/ai` driver tests — index individual keys.
- **Timeout applies per attempt, not per retry sequence** — `retry()` re-dispatches the same closure, so each attempt gets the full timeout. A request with `withTimeout(3)->retry(2)` can therefore take up to ~9 seconds plus sleeps. Capping total elapsed time is an application-layer concern.
- **Streaming is a pull over a push** — cURL pushes body bytes into `WRITEFUNCTION` while a transfer runs; generators pull. `CurlStreamHandle::pump()` drives one easy handle through its own `curl_multi` only when the consumer asks for the next chunk. Nothing pumps while nobody pulls, so the socket buffer fills and the server waits — back-pressure without a memory bound to tune. Rejected: Fibers suspended from a cURL C callback (fragile, hidden) and a blocking callback API (does not compose with `AiStream` or `StreamedResponse`).
- **Idle timeout, no total timeout, for streams** — a streamed answer may run for minutes. The transfer fails only when no data arrives for `withIdleTimeout()` seconds (default `HttpRequest::DEFAULT_IDLE_TIMEOUT_SECONDS`, 30). The idle clock runs inside `pump()` only, so a slow consumer between pulls never looks like a silent server. Connect is bounded separately by `CurlTransport::CONNECT_TIMEOUT_SECONDS` (10).
- **`stream()` returns after the final header block** — `HEADERFUNCTION` starts a new block on each `HTTP/` status line and treats a block as final unless it is `1xx` or a `3xx` with `Location`. Providers send headers at once but the first token much later, so waiting for body bytes would delay error handling.
- **Who closes the connection** — before iteration starts, `HttpStream::close()` / `__destruct()`; once `getIterator()` was called, the generator owns it (its `finally` closes the handle when it finishes or is destroyed). Otherwise `$it = $http->stream()->getIterator()` would lose its connection the moment the temporary `HttpStream` is freed — `CurlTransportStreamTest` caught exactly that.
- **`StreamingTransportInterface` is separate from `TransportInterface`** — widening `TransportInterface` again (see the timeout decision above) would have broken every external transport and all in-repo test doubles for a capability most of them never need. `HttpRequest::stream()` checks for the interface and throws a clear `HttpClientException` otherwise.
- **`stream()` rejects `retry()` and `withMiddleware()`** — a stream cannot restart after its first byte, and middleware closures are typed for a complete `HttpResponse`. Throwing is explicit; silently ignoring them would not be.
- **`HttpClientException` is not final** — `HttpStreamException` extends it so one `catch (HttpClientException)` still covers every transport failure.
- **`curl_close()` is never called** — deprecated in PHP 8.5 and without effect since 8.0; `curl_multi_remove_handle()` + `curl_multi_close()` release the connection.

---
- **`Backoff` is a separate value object attached with `HttpRequest::backoff()`, not an extra `retry()` parameter.** `retry(int $times, int $sleepMs = 100, ?Closure $when = null)` keeps its signature (a public API); `backoff()` is an additive clone-based wither that, when set, replaces the fixed `$sleepMs` between attempts and has no effect without `retry()`. Exponential backoff defaults to *equal jitter* (a random value in `[delay/2, delay]`): clients that failed together do not retry in lock-step, and the wait never collapses to ~0 the way full jitter can. The random source is injectable so tests are deterministic.
- **The circuit breaker is a `TransportInterface` decorator, so it works for every request.** `CircuitBreakerTransport` wraps any transport (`new HttpClient(new CircuitBreakerTransport(new CurlTransport(), $cache))`). State lives in a `CacheInterface`, keyed per host by default (`keyResolver` overrides), so all PHP processes sharing the cache share the circuit. Closed → open after `failureThreshold` failures within `failureWindowSeconds`; open rejects for `openSeconds`; then half-open lets exactly one probe through (guarded by a cache lock — other callers still fail fast), which closes the circuit on success or re-opens it on failure. A failure is an `HttpClientException` or a 5xx response (`isFailure` overrides); 4xx never counts. The counter is a read-modify-write on the cache, so under heavy concurrency it is approximate — the breaker may trip a request or two early or late, which is acceptable for a protective mechanism.
- **`CircuitOpenException` is an `HttpClientException` but is never retried.** Existing `catch (HttpClientException)` code keeps working, while `HttpRequest::retry()` rethrows it immediately: a circuit stays open for seconds, so retrying after milliseconds only burns the attempt budget.
- **The wrapper does not implement `StreamingTransportInterface`.** Only `send()` is protected; `stream()` on a wrapped client fails with the existing "transport does not support streaming" error. Use the undecorated transport for streams (their failure modes are idle timeouts, not fast-failing connections).
- **`ez-php/cache` is a soft dependency (`require-dev` + `suggest`).** Only `CircuitBreakerTransport` references it, and PSR-4 loads it only when used.
- **`Http` facade builds its own default client** — when no client has been set, `Http::getClient()` creates `new HttpClient(new CurlTransport())` so the façade works without a service provider (scripts, tests). This is the one façade that instantiates a collaborator itself; it is intentional because the module has no required configuration. Use `Http::fake()` in tests.

## Testing Approach

- **No real network calls in unit tests** — Implement a `TransportInterface` test double (anonymous class or stub) that returns a hard-coded `HttpResponse`. Pass it to `HttpClient` directly or via `Http::setClient()`.
- **Always call `Http::resetClient()`** in `setUp()` and `tearDown()` of any test that touches the `Http` façade. Omitting this leaks a client instance between tests.
- **`CurlTransport::send()` against the internet, `stream()` against loopback** — `tests/Integration/CurlTransportIntegrationTest.php` needs httpbin.org and is excluded from the default suite. `CurlTransportStreamTest` starts `php -S 127.0.0.1:<free port>` with `PHP_CLI_SERVER_WORKERS=4` (a hanging endpoint would otherwise block the next test) and runs in the default suite: incremental arrival, redirects, idle timeout before and after headers, a dropped connection, error statuses, and a server-side marker proving `close()` and a dropped iterator abort the transfer.
- **`HttpRequest` builder tests** — Construct with a test-double transport, chain builder methods, call `send()`, assert the transport received the expected method/URL/headers/body and that the returned `HttpResponse` is passed through correctly.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Declare indirectly used classes with `#[UsesClass]`.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Incoming HTTP requests (server-side) | `ez-php/http` (`Request`, `RequestFactory`) |
| Response caching | `ez-php/cache` or application layer |
| Authentication for outgoing requests (OAuth, API key injection) | Application layer (configure via `withHeader()`) |
| Retry policies beyond `retry()` + `Backoff` (honouring `Retry-After`, retry budgets, hedged requests) | Application layer or a decorator wrapping `TransportInterface` |
| Bulkheads / adaptive concurrency limits | Application layer — the circuit breaker only trips on consecutive failures |
| Total-elapsed-time budget across a retry sequence | Application layer — `withTimeout()` bounds each attempt, not the whole sequence |
| Configurable connect timeout (`CURLOPT_CONNECTTIMEOUT`) | Not exposed; `send()` has only the total timeout, `stream()` a fixed 10 s connect timeout |
| Event-loop / promise-based async (fibers, ReactPHP) | Out of scope — `Pool`/`PooledRequest` (`Http::async()`, `Http::pool()`) provide concurrency via `curl_multi_exec`, which is blocking-but-parallel, not an event loop |
| Streaming for `Pool`, or retry/middleware for streams | Out of scope — see the `stream()` design decisions above |
| `EventSource` reconnection (`retry`, `Last-Event-ID`) | Application layer — `SseDecoder` only decodes |
| Streaming multipart uploads (files larger than memory) | Application layer — `HttpRequest::attach()` builds the multipart body in memory |
