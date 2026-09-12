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
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum` and
`opcache` → `OPCache` are existing exceptions the guess gets wrong).

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^1.0"` in `require-dev`):

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

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 | — |
| `ez-php/queue` | 3310 | 6381 | — |
| `ez-php/rate-limiter` | — | 6382 | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

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
├── HttpClient.php                 — Entry point; factory methods returning a configured HttpRequest
├── HttpRequest.php                — Fluent builder for a pending request; dispatches via transport
├── HttpResponse.php               — Immutable value object wrapping the response (status, body, headers)
├── HttpClientException.php        — Thrown on transport failures (not on 4xx/5xx responses)
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
| Total-elapsed-time budget across a retry sequence | Application layer — `withTimeout()` bounds each attempt, not the whole sequence |
| Connect timeout separate from total timeout (`CURLOPT_CONNECTTIMEOUT`) | Not exposed; only the total timeout is configurable |
| Event-loop / promise-based async (fibers, ReactPHP) | Out of scope — `Pool`/`PooledRequest` (`Http::async()`, `Http::pool()`) provide concurrency via `curl_multi_exec`, which is blocking-but-parallel, not an event loop |
| Streaming responses | Out of scope |
| Streaming multipart uploads (files larger than memory) | Application layer — `HttpRequest::attach()` builds the multipart body in memory |
