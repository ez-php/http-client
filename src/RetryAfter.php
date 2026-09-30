<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class RetryAfter
 *
 * Parses a `Retry-After` response header (RFC 9110 §10.2.3) into a wait in
 * milliseconds: either delay-seconds (`"120"`) or an HTTP-date
 * (`"Fri, 31 Dec 1999 23:59:59 GMT"`, relative to `$now`; a past date means 0).
 *
 * @internal Used by HttpRequest::respectRetryAfter(); not part of the public API.
 * @package EzPhp\HttpClient
 */
final class RetryAfter
{
    /**
     * @param string $value The header value.
     * @param int    $now   Current Unix time, for HTTP-date values.
     *
     * @return int|null Milliseconds to wait, or null when the value is not valid.
     */
    public static function delayMs(string $value, int $now): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value * 1000;
        }

        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));

        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now) * 1000;
    }
}
