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
 * FakeTransport::sequence() scripts the call *order* instead of URLs: every
 * request takes the next queued fixture (the same URL answering 401 then 200,
 * a queued HttpClientException), and a request beyond the queue throws a
 * LogicException — deliberately not an HttpClientException, so code under
 * test cannot swallow it as a network error.
 *
 * withConnectTimeout() records the value on the next request (this same
 * instance, so getRecorded() keeps working) instead of applying it; withoutRedirects()
 * records `followRedirects: false` the same way.
 *
 * @package EzPhp\HttpClient
 */
final class FakeTransport implements StreamingTransportInterface, ConnectTimeoutTransportInterface, RedirectControlTransportInterface
{
    /**
     * @var array<string, HttpResponse|HttpStream|HttpClientException>
     */
    private array $responseMap;

    /**
     * Ordered fixtures in sequence mode; null in URL-map mode.
     *
     * @var list<HttpResponse|HttpStream|HttpClientException>|null
     */
    private ?array $sequence = null;

    /**
     * Connect timeout handed over by withConnectTimeout() for the next request.
     */
    private ?int $pendingConnectTimeout = null;

    /**
     * False when withoutRedirects() applies to the next request.
     */
    private bool $pendingFollowRedirects = true;

    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeoutSeconds: int|null, idleTimeoutSeconds: int|null, connectTimeoutSeconds: int|null, followRedirects: bool}>
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
     * Create a fake that answers requests strictly in order, whatever their URL.
     *
     * @param list<HttpResponse|HttpStream|HttpClientException> $fixtures One per expected request.
     *
     * @return self
     */
    public static function sequence(array $fixtures): self
    {
        $fake = new self();
        $fake->sequence = $fixtures;

        return $fake;
    }

    /**
     * Number of queued sequence fixtures not consumed yet (0 in URL-map mode).
     *
     * @return int
     */
    public function pending(): int
    {
        return count($this->sequence ?? []);
    }

    /**
     * Record the opt-out on the next request (see the class docblock).
     *
     * @return self
     */
    public function withoutRedirects(): self
    {
        $this->pendingFollowRedirects = false;

        return $this;
    }

    /**
     * Record the connect timeout on the next request (see the class docblock).
     *
     * @param int $seconds
     *
     * @return self
     */
    public function withConnectTimeout(int $seconds): self
    {
        $this->pendingConnectTimeout = $seconds;

        return $this;
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

        $fixture = $this->next($method, $url);

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

        $fixture = $this->next($method, $url);

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
     * `idleTimeoutSeconds` is null for send(); `timeoutSeconds` is null for stream();
     * `connectTimeoutSeconds` is null unless the request set withConnectTimeout();
     * `followRedirects` is false when it set withoutRedirects().
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string, timeoutSeconds: int|null, idleTimeoutSeconds: int|null, connectTimeoutSeconds: int|null, followRedirects: bool}>
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
            'connectTimeoutSeconds' => $this->pendingConnectTimeout,
            'followRedirects' => $this->pendingFollowRedirects,
        ];
        $this->pendingConnectTimeout = null;
        $this->pendingFollowRedirects = true;
    }

    /**
     * The fixture for this request: the next queued one in sequence mode, else the URL match.
     *
     * @param string $method
     * @param string $url
     *
     * @return HttpResponse|HttpStream|HttpClientException|null
     * @throws \LogicException When a sequence is exhausted.
     */
    private function next(string $method, string $url): HttpResponse|HttpStream|HttpClientException|null
    {
        if ($this->sequence === null) {
            return $this->match($url);
        }

        if ($this->sequence === []) {
            throw new \LogicException(sprintf(
                'FakeTransport sequence exhausted: unexpected request #%d %s %s.',
                count($this->recorded),
                $method,
                $url,
            ));
        }

        return array_shift($this->sequence);
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
