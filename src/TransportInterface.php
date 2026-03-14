<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Interface TransportInterface
 *
 * The single I/O seam for the HTTP client. Replace with a test double to
 * avoid real network calls in unit tests.
 *
 * @package EzPhp\HttpClient
 */
interface TransportInterface
{
    /**
     * Send an HTTP request and return the response.
     *
     * @param string                $method   HTTP verb (GET, POST, …).
     * @param string                $url      Fully qualified URL.
     * @param array<string, string> $headers  Request headers.
     * @param string                $body     Raw request body.
     *
     * @return HttpResponse
     * @throws HttpClientException When the transport layer fails.
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse;
}
