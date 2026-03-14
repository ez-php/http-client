<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use RuntimeException;

/**
 * Class HttpClientException
 *
 * Thrown when the transport layer fails (e.g. curl error, DNS failure).
 * HTTP error status codes (4xx, 5xx) are NOT exceptions — they are valid
 * HttpResponse objects with the appropriate status code.
 *
 * @package EzPhp\HttpClient
 */
final class HttpClientException extends RuntimeException
{
}
