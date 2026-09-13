<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class HttpStreamException
 *
 * Thrown while iterating an HttpStream when the transfer fails after the
 * response headers arrived — the idle timeout elapsed or the connection was
 * lost mid-body. Failures before the headers throw HttpClientException from
 * stream() itself.
 *
 * @package EzPhp\HttpClient
 */
final class HttpStreamException extends HttpClientException
{
}
