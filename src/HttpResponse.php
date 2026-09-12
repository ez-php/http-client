<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class HttpResponse
 *
 * Immutable value object wrapping the result of an HTTP request.
 *
 * @package EzPhp\HttpClient
 */
final readonly class HttpResponse
{
    /**
     * HttpResponse Constructor
     *
     * @param int                    $statusCode
     * @param string                 $rawBody
     * @param array<string, string>  $headers  Header names normalised to lowercase.
     */
    public function __construct(
        private int $statusCode,
        private string $rawBody,
        private array $headers = [],
    ) {
    }

    /**
     * Build a synthetic HttpResponse for use with Http::fake()/FakeTransport,
     * without needing to construct the raw body/headers by hand.
     *
     * An array $body is JSON-encoded automatically and given a
     * `Content-Type: application/json` header; a string $body is used as-is.
     *
     * @param array<string, mixed>|string $body    Array is JSON-encoded automatically.
     * @param int                         $status  HTTP status code (default 200).
     * @param array<string, string>       $headers Response headers (any case; normalised to lowercase).
     *
     * @return self
     */
    public static function fake(array|string $body = '', int $status = 200, array $headers = []): self
    {
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);

        if (is_array($body)) {
            $rawBody = (string) json_encode($body);
            $normalizedHeaders['content-type'] = 'application/json';
        } else {
            $rawBody = $body;
        }

        return new self($status, $rawBody, $normalizedHeaders);
    }

    /**
     * @return int
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function body(): string
    {
        return $this->rawBody;
    }

    /**
     * Decode the response body as JSON.
     *
     * Returns null when the body is empty or not valid JSON.
     *
     * @return mixed
     */
    public function json(): mixed
    {
        if ($this->rawBody === '') {
            return null;
        }

        return json_decode($this->rawBody, true);
    }

    /**
     * @param string $name     Header name (case-insensitive).
     * @param string $default  Returned when the header is absent.
     *
     * @return string
     */
    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Returns true when the status code indicates success (2xx).
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
