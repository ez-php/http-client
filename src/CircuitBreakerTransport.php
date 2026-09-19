<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use EzPhp\Cache\CacheInterface;

/**
 * Class CircuitBreakerTransport
 *
 * `TransportInterface` decorator that stops calling a service that keeps failing, so callers
 * fail fast (with `CircuitOpenException`) instead of piling up timeouts, and the service gets
 * room to recover.
 *
 *     $http = new HttpClient(new CircuitBreakerTransport(new CurlTransport(), $cache));
 *
 * States, kept per circuit (by default one per host) in a `CacheInterface`, so every PHP
 * process sharing the cache shares the circuit:
 *
 *  - **closed** — requests pass through. `failureThreshold` failures within `failureWindowSeconds`
 *    open the circuit; a success clears the count.
 *  - **open** — requests fail immediately for `openSeconds`.
 *  - **half-open** — after that time exactly one probe request is let through (guarded by a cache
 *    lock, so concurrent callers still fail fast). A successful probe closes the circuit, a failed
 *    one re-opens it for another `openSeconds`.
 *
 * A failure is an `HttpClientException` or, by default, a 5xx response; 4xx responses are the
 * service answering correctly and never count. The counter is read-modify-write on the cache and
 * is therefore approximate under heavy concurrency — the breaker may trip one or two requests
 * early or late, which is fine for its purpose.
 *
 * Only `send()` is decorated: the wrapper does not implement `StreamingTransportInterface`, so
 * `stream()` needs the undecorated transport.
 *
 * `ez-php/cache` is a soft dependency (`suggest`): this class is only autoloaded when used.
 *
 * @package EzPhp\HttpClient
 */
final class CircuitBreakerTransport implements TransportInterface
{
    private const string KEY_PREFIX = 'http-client:circuit:';

    /**
     * @var \Closure(HttpResponse): bool
     */
    private readonly \Closure $isFailure;

    /**
     * @var \Closure(string): string
     */
    private readonly \Closure $keyResolver;

    /**
     * @var \Closure(): int
     */
    private readonly \Closure $clock;

    /**
     * CircuitBreakerTransport Constructor
     *
     * @param TransportInterface                  $inner
     * @param CacheInterface                      $cache
     * @param int                                 $failureThreshold    Failures within the window that open the circuit.
     * @param int                                 $openSeconds         How long an open circuit rejects requests before allowing a probe.
     * @param int                                 $failureWindowSeconds Failures older than this no longer count.
     * @param (\Closure(HttpResponse): bool)|null $isFailure           Classifies a response as a failure; default: status >= 500.
     * @param (\Closure(string): string)|null     $keyResolver         Maps a URL to a circuit name; default: its host.
     * @param (\Closure(): int)|null              $clock               Current Unix time; default `time()` (tests inject their own).
     */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly CacheInterface $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 30,
        private readonly int $failureWindowSeconds = 60,
        ?\Closure $isFailure = null,
        ?\Closure $keyResolver = null,
        ?\Closure $clock = null,
    ) {
        $this->isFailure = $isFailure ?? static fn (HttpResponse $response): bool => $response->status() >= 500;
        $this->keyResolver = $keyResolver ?? static function (string $url): string {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : $url;
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int|null              $timeoutSeconds
     *
     * @throws CircuitOpenException When the circuit for this service is open.
     *
     * @return HttpResponse
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        ?int $timeoutSeconds = null,
    ): HttpResponse {
        $circuit = ($this->keyResolver)($url);
        $key = self::KEY_PREFIX . $circuit;
        $now = ($this->clock)();
        $state = $this->load($key);

        $probe = null;

        if ($state['opened_at'] !== null) {
            $elapsed = $now - $state['opened_at'];

            if ($elapsed < $this->openSeconds) {
                throw new CircuitOpenException($circuit, $this->openSeconds - $elapsed);
            }

            // Half-open: one caller probes, everyone else keeps failing fast.
            $probe = $this->cache->lock($key . ':probe', $this->openSeconds);

            if (!$probe->acquire()) {
                throw new CircuitOpenException($circuit, 1);
            }
        }

        try {
            $response = $this->inner->send($method, $url, $headers, $body, $timeoutSeconds);
        } catch (HttpClientException $e) {
            $this->recordFailure($key, $state, $now, $probe !== null);

            throw $e;
        } finally {
            // Success paths below release too; a failure recorded above is already stored by now.
            if ($probe !== null) {
                $probe->release();
            }
        }

        if (($this->isFailure)($response)) {
            $this->recordFailure($key, $state, $now, $probe !== null);

            return $response;
        }

        if ($state['failures'] > 0 || $state['opened_at'] !== null) {
            $this->cache->forget($key);
        }

        return $response;
    }

    /**
     * @param string $key
     *
     * @return array{failures: int, first_failure_at: int, opened_at: int|null}
     */
    private function load(string $key): array
    {
        $raw = $this->cache->get($key);
        $failures = is_array($raw) && is_int($raw['failures'] ?? null) ? $raw['failures'] : 0;
        $first = is_array($raw) && is_int($raw['first_failure_at'] ?? null) ? $raw['first_failure_at'] : 0;
        $opened = is_array($raw) && is_int($raw['opened_at'] ?? null) ? $raw['opened_at'] : null;

        return ['failures' => $failures, 'first_failure_at' => $first, 'opened_at' => $opened];
    }

    /**
     * @param string                                                                $key
     * @param array{failures: int, first_failure_at: int, opened_at: int|null}      $state
     * @param int                                                                   $now
     * @param bool                                                                  $wasProbe
     *
     * @return void
     */
    private function recordFailure(string $key, array $state, int $now, bool $wasProbe): void
    {
        if ($wasProbe) {
            // The probe failed: stay open for another full period.
            $state['opened_at'] = $now;
        } else {
            $windowExpired = $state['failures'] === 0 || $now - $state['first_failure_at'] > $this->failureWindowSeconds;

            $state['failures'] = $windowExpired ? 1 : $state['failures'] + 1;
            $state['first_failure_at'] = $windowExpired ? $now : $state['first_failure_at'];

            if ($state['failures'] >= $this->failureThreshold) {
                $state['opened_at'] = $now;
            }
        }

        $this->cache->set($key, $state, max($this->failureWindowSeconds, $this->openSeconds) * 2);
    }
}
