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
 * When no pattern matches, a 200 OK empty response (or stream) is returned.
 *
 * Fixtures work for both methods: stream() on an HttpResponse yields its body
 * as one chunk; send() on an HttpStream drains it. An HttpStream fixture is
 * single use — register a fresh one per streamed request.
 *
 * @package EzPhp\HttpClient
 */
final class FakeTransport implements StreamingTransportInterface
{
    /**
     * @var array<string, HttpResponse|HttpStream|HttpClientException>
     */
    private array $responseMap;

    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeoutSeconds: int|null, idleTimeoutSeconds: int|null}>
     */
    private array $recorded = [];

    /**
     * FakeTransport Constructor
     *
     * @param array<string, HttpResponse|HttpStream|HttpClientException> $responseMap
     *   Keys are URL patterns (fnmatch syntax), values are the fixture to return or the exception to throw.
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
     * @param int|null              $timeoutSeconds Recorded so tests can assert the timeout reached the transport.
     *
     * @return HttpResponse
     * @throws HttpClientException When the matched stub is an exception.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        ?int $timeoutSeconds = null,
    ): HttpResponse {
        $this->record($method, $url, $headers, $body, $timeoutSeconds, null);

        $fixture = $this->match($url);

        if ($fixture instanceof HttpClientException) {
            throw $fixture;
        }

        if ($fixture instanceof HttpStream) {
            return new HttpResponse($fixture->status(), $fixture->body(), $fixture->headers());
        }

        return $fixture ?? new HttpResponse(200, '');
    }

    /**
     * Record the request and return the first matching fixture as a stream.
     *
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int                   $idleTimeoutSeconds Recorded so tests can assert it reached the transport.
     *
     * @return HttpStream
     * @throws HttpClientException When the matched stub is an exception.
     */
    public function stream(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $idleTimeoutSeconds,
    ): HttpStream {
        $this->record($method, $url, $headers, $body, null, $idleTimeoutSeconds);

        $fixture = $this->match($url);

        if ($fixture instanceof HttpClientException) {
            throw $fixture;
        }

        if ($fixture instanceof HttpResponse) {
            $chunks = $fixture->body() === '' ? [] : [$fixture->body()];

            return HttpStream::fake($chunks, $fixture->status(), $fixture->headers());
        }

        return $fixture ?? HttpStream::fake();
    }

    /**
     * Return all recorded requests in the order they were made.
     *
     * `idleTimeoutSeconds` is null for send(); `timeoutSeconds` is null for stream().
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string, timeoutSeconds: int|null, idleTimeoutSeconds: int|null}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int|null              $timeoutSeconds
     * @param int|null              $idleTimeoutSeconds
     *
     * @return void
     */
    private function record(
        string $method,
        string $url,
        array $headers,
        string $body,
        ?int $timeoutSeconds,
        ?int $idleTimeoutSeconds,
    ): void {
        $this->recorded[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutSeconds' => $timeoutSeconds,
            'idleTimeoutSeconds' => $idleTimeoutSeconds,
        ];
    }

    /**
     * @param string $url
     *
     * @return HttpResponse|HttpStream|HttpClientException|null
     */
    private function match(string $url): HttpResponse|HttpStream|HttpClientException|null
    {
        foreach ($this->responseMap as $pattern => $fixture) {
            if ($this->matches($pattern, $url)) {
                return $fixture;
            }
        }

        return null;
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
