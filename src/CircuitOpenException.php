<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class CircuitOpenException
 *
 * Thrown by `CircuitBreakerTransport` instead of calling a service whose circuit is open.
 * It is an `HttpClientException`, so existing `catch (HttpClientException)` code keeps working;
 * `HttpRequest::retry()` deliberately does *not* retry it (waiting a few milliseconds cannot
 * close a circuit that stays open for seconds).
 *
 * @package EzPhp\HttpClient
 */
final class CircuitOpenException extends HttpClientException
{
    /**
     * CircuitOpenException Constructor
     *
     * @param string $circuit           Name of the circuit (by default the host).
     * @param int    $retryAfterSeconds Seconds until a probe request is allowed again.
     */
    public function __construct(
        public readonly string $circuit,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Circuit for '{$circuit}' is open; retry in {$retryAfterSeconds}s.");
    }
}
