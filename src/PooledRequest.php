<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class PooledRequest
 *
 * A pending HTTP request queued inside a Pool.
 * Provides the same fluent builder API as HttpRequest but defers execution
 * until the pool runs all requests concurrently.
 *
 * After Pool::wait() completes, call response() to retrieve the result.
 *
 * @package EzPhp\HttpClient
 */
final class PooledRequest
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    private string $body = '';

    private ?HttpResponse $resolved = null;

    /**
     * PooledRequest Constructor
     *
     * @param string $method
     * @param string $url
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
    ) {
    }

    /**
     * Merge additional headers into the request.
     *
     * @param array<string, string> $headers
     *
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /**
     * Set a single request header.
     *
     * @param string $name
     * @param string $value
     *
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * Set a raw string body.
     *
     * @param string $body
     *
     * @return self
     */
    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /**
     * Set a JSON-encoded body and add the appropriate Content-Type header.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public function withJson(array $data): self
    {
        $clone = clone $this;
        $clone->body = (string) json_encode($data);
        $clone->headers['Content-Type'] = 'application/json';

        return $clone;
    }

    /**
     * Set a form-encoded body and add the appropriate Content-Type header.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public function withForm(array $data): self
    {
        $clone = clone $this;
        $clone->body = http_build_query($data);
        $clone->headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $clone;
    }

    /**
     * Resolve this request with its response.
     * Called internally by Pool after execution.
     *
     * @param HttpResponse $response
     *
     * @return void
     */
    public function resolve(HttpResponse $response): void
    {
        $this->resolved = $response;
    }

    /**
     * Return the resolved response.
     * Must be called after Pool::wait().
     *
     * @return HttpResponse
     * @throws HttpClientException When the request has not been executed yet.
     */
    public function response(): HttpResponse
    {
        if ($this->resolved === null) {
            throw new HttpClientException('PooledRequest has not been executed yet. Call Pool::wait() first.');
        }

        return $this->resolved;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }
}
