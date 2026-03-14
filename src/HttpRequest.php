<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class HttpRequest
 *
 * Fluent builder for a pending HTTP request.
 * Constructed by HttpClient; not instantiated directly by application code.
 *
 * Usage:
 *
 *   Http::get('https://api.example.com/users')
 *       ->withHeaders(['Authorization' => 'Bearer token'])
 *       ->json();
 *
 * @package EzPhp\HttpClient
 */
final class HttpRequest
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    private string $body = '';

    /**
     * HttpRequest Constructor
     *
     * @param string             $method
     * @param string             $url
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly TransportInterface $transport,
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
        $clone = clone($this, [
            'headers' => array_merge($this->headers, $headers),
        ]);

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
        $clone = clone($this, [
            'body' => $body,
        ]);

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
     * Send the request and return the full response object.
     *
     * @return HttpResponse
     * @throws HttpClientException
     */
    public function send(): HttpResponse
    {
        return $this->transport->send($this->method, $this->url, $this->headers, $this->body);
    }

    /**
     * Send and return the decoded JSON body.
     *
     * @return mixed
     * @throws HttpClientException
     */
    public function json(): mixed
    {
        return $this->send()->json();
    }

    /**
     * Send and return the raw response body.
     *
     * @return string
     * @throws HttpClientException
     */
    public function body(): string
    {
        return $this->send()->body();
    }

    /**
     * Send and return the HTTP status code.
     *
     * @return int
     * @throws HttpClientException
     */
    public function status(): int
    {
        return $this->send()->status();
    }
}
