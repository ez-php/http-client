<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class RequestHeaders
 *
 * Case-insensitive writes for outgoing request headers. HTTP header names are
 * case-insensitive, so setting 'content-type' after 'Content-Type' must replace
 * the entry rather than send the header twice. Mirrors `ez-php/http`'s
 * `Headers::set()`, which this package does not depend on.
 *
 * @internal Used by HttpRequest and PooledRequest; not part of the public API.
 * @package EzPhp\HttpClient
 */
final class RequestHeaders
{
    /**
     * Set a header, removing any existing entry whose name differs only in case.
     *
     * @param array<string, string> $headers
     * @param string                $name
     * @param string                $value
     *
     * @return array<string, string>
     */
    public static function set(array $headers, string $name, string $value): array
    {
        $lower = strtolower($name);

        foreach (array_keys($headers) as $existing) {
            if (strtolower($existing) === $lower) {
                unset($headers[$existing]);
            }
        }

        $headers[$name] = $value;

        return $headers;
    }

    /**
     * Set every header in $additional, in order, via set().
     *
     * @param array<string, string> $headers
     * @param array<string, string> $additional
     *
     * @return array<string, string>
     */
    public static function merge(array $headers, array $additional): array
    {
        foreach ($additional as $name => $value) {
            $headers = self::set($headers, $name, $value);
        }

        return $headers;
    }
}
