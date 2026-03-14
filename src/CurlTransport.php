<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class CurlTransport
 *
 * cURL-based implementation of TransportInterface.
 * All curl logic is isolated here — no curl calls exist anywhere else.
 *
 * @package EzPhp\HttpClient
 */
final class CurlTransport implements TransportInterface
{
    private const int TIMEOUT_SECONDS = 30;

    /**
     * @param string                $method
     * @param string                $url
     * @param array<string, string> $headers
     * @param string                $body
     *
     * @return HttpResponse
     * @throws HttpClientException
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);

        if (!is_string($result)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new HttpClientException('cURL error: ' . $error);
        }

        /** @var int $headerSize */
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        /** @var int $statusCode */
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $rawHeaders = substr($result, 0, $headerSize);
        $responseBody = substr($result, $headerSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return new HttpResponse($statusCode, $responseBody, $parsedHeaders);
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
