<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class FakeTransport
 *
 * In-memory transport stub for deterministic testing.
 * Installed via Http::fake() — records all requests and returns pre-configured responses.
 *
 * URL patterns follow fnmatch() syntax ('*' matches any URL, 'https://api.*' prefix wildcard, etc.).
 * When no pattern matches, a 200 OK empty response is returned.
 *
 * @package EzPhp\HttpClient
 */
final class FakeTransport implements TransportInterface
{
    /**
     * @var array<string, HttpResponse|HttpClientException>
     */
    private array $responseMap;

    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    private array $recorded = [];

    /**
     * FakeTransport Constructor
     *
     * @param array<string, HttpResponse|HttpClientException> $responseMap
     *   Keys are URL patterns (fnmatch syntax), values are HttpResponse or HttpClientException.
     */
    public function __construct(array $responseMap = [])
    {
        $this->responseMap = $responseMap;
    }

    /**
     * Record the request and return the first matching response.
     *
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     *
     * @return HttpResponse
     * @throws HttpClientException When the matched stub is an exception.
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->recorded[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        foreach ($this->responseMap as $pattern => $response) {
            if ($this->matches($pattern, $url)) {
                if ($response instanceof HttpClientException) {
                    throw $response;
                }

                return $response;
            }
        }

        return new HttpResponse(200, '');
    }

    /**
     * Return all recorded requests in the order they were made.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * Check whether a URL matches the given fnmatch pattern.
     *
     * @param string $pattern
     * @param string $url
     *
     * @return bool
     */
    private function matches(string $pattern, string $url): bool
    {
        if ($pattern === '*') {
            return true;
        }

        return fnmatch($pattern, $url);
    }
}
