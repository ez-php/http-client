<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Interface ConnectTimeoutTransportInterface
 *
 * Optional capability of a transport: a bounded connection phase
 * (`CURLOPT_CONNECTTIMEOUT`), so a dead host fails fast instead of consuming
 * the whole total timeout. HttpRequest::withConnectTimeout() uses it for
 * send() and stream() when the transport implements it, and leaves other
 * transports alone. Kept apart from TransportInterface so adding it does not
 * break existing implementations (see the timeout design decision).
 *
 * @package EzPhp\HttpClient
 */
interface ConnectTimeoutTransportInterface
{
    /**
     * Return a transport that applies the connect timeout to its next requests.
     *
     * @param int $seconds Connection-phase timeout in seconds.
     *
     * @return TransportInterface
     */
    public function withConnectTimeout(int $seconds): TransportInterface;
}
