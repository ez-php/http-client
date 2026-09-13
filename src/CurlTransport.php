<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use CurlHandle;
use Generator;

/**
 * Class CurlTransport
 *
 * cURL-based implementation of StreamingTransportInterface.
 * All curl logic is isolated here and in CurlStreamHandle — no curl calls
 * exist anywhere else.
 *
 * @package EzPhp\HttpClient
 */
final class CurlTransport implements StreamingTransportInterface
{
    /**
     * Fallback timeout used when the caller does not supply one.
     */
    public const int TIMEOUT_SECONDS = 30;

    /**
     * Connect timeout for streamed requests, which have no total timeout.
     */
    public const int CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int|null              $timeoutSeconds Total request timeout; null uses TIMEOUT_SECONDS.
     *
     * @return HttpResponse
     * @throws HttpClientException
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        ?int $timeoutSeconds = null,
    ): HttpResponse {
        $ch = $this->createHandle($method, $url, $headers, $body);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds ?? self::TIMEOUT_SECONDS);

        $result = curl_exec($ch);

        if (!is_string($result)) {
            $error = curl_error($ch);
            throw new HttpClientException('cURL error: ' . $error);
        }

        /** @var int $headerSize */
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        /** @var int $statusCode */
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $rawHeaders = substr($result, 0, $headerSize);
        $responseBody = substr($result, $headerSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return new HttpResponse($statusCode, $responseBody, $parsedHeaders);
    }

    /**
     * Open the request and return once the final response headers arrived.
     *
     * No total timeout applies: the transfer fails only when nothing arrives
     * for $idleTimeoutSeconds (or the connect phase exceeds CONNECT_TIMEOUT_SECONDS).
     *
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     * @param int                   $idleTimeoutSeconds
     *
     * @return HttpStream
     * @throws HttpClientException When the connection fails before response headers arrive.
     */
    public function stream(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $idleTimeoutSeconds,
    ): HttpStream {
        $ch = $this->createHandle($method, $url, $headers, $body);

        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);

        $handle = new CurlStreamHandle($ch);

        while (!$handle->headersComplete() && !$handle->isDone()) {
            if (!$handle->pump($idleTimeoutSeconds)) {
                $handle->close();

                throw new HttpClientException(sprintf('No response headers within %d seconds.', $idleTimeoutSeconds));
            }
        }

        if (!$handle->headersComplete() && $handle->failed()) {
            $error = $handle->error();
            $handle->close();

            throw new HttpClientException('cURL error: ' . $error);
        }

        return new HttpStream(
            $handle->status(),
            $handle->headers(),
            static function () use ($handle, $idleTimeoutSeconds): Generator {
                yield from self::chunks($handle, $idleTimeoutSeconds);
            },
            $handle->close(...),
        );
    }

    /**
     * @param CurlStreamHandle $handle
     * @param int              $idleTimeoutSeconds
     *
     * @return Generator<int, string, void, void>
     * @throws HttpStreamException
     */
    private static function chunks(CurlStreamHandle $handle, int $idleTimeoutSeconds): Generator
    {
        try {
            while (!$handle->isClosed()) {
                if ($handle->hasBufferedData()) {
                    yield $handle->takeBuffer();

                    continue;
                }

                if ($handle->isDone()) {
                    if ($handle->failed()) {
                        throw new HttpStreamException('Connection lost: ' . $handle->error());
                    }

                    return;
                }

                if (!$handle->pump($idleTimeoutSeconds)) {
                    throw new HttpStreamException(sprintf('Stream idle for %d seconds.', $idleTimeoutSeconds));
                }
            }
        } finally {
            $handle->close();
        }
    }

    /**
     * Validate the request and create an easy handle with the options shared by send() and stream().
     *
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     *
     * @return CurlHandle
     * @throws HttpClientException
     */
    private function createHandle(string $method, string $url, array $headers, string $body): CurlHandle
    {
        if ($url === '') {
            throw new HttpClientException('URL cannot be empty.');
        }

        $upperMethod = strtoupper($method);

        if ($upperMethod === '') {
            throw new HttpClientException('HTTP method cannot be empty.');
        }

        $ch = curl_init();

        if ($ch === false) {
            throw new HttpClientException('Failed to initialize curl handle.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return $ch;
    }

    /**
     * Convert an associative headers array to the "Name: value" format curl expects.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    /**
     * Parse the raw header block into an associative array.
     * Header names are normalised to lowercase.
     * When redirects occur, only the last header block is kept.
     *
     * @param string $rawHeaders
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        // Split on double CRLF to separate redirect blocks; use the final block.
        $blocks = array_filter(array_map('trim', explode("\r\n\r\n", $rawHeaders)));
        $lastBlock = end($blocks);

        if ($lastBlock === false) {
            return [];
        }

        $parsed = [];

        foreach (explode("\r\n", $lastBlock) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        return $parsed;
    }
}
