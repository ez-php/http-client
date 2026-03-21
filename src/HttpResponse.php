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
