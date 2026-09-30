<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class CurlHeaders
 *
 * Header conversion shared by the two cURL paths — CurlTransport::send() and
 * Pool's curl_multi path — so both format request headers and parse redirect
 * header blocks identically.
 *
 * @internal Used by CurlTransport and Pool; not part of the public API.
 * @package EzPhp\HttpClient
 */
final class CurlHeaders
{
    /**
     * Convert an associative headers array to the "Name: value" format curl expects.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    public static function format(array $headers): array
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
    public static function parse(string $rawHeaders): array
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
