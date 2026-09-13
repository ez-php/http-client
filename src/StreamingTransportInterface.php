<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Interface StreamingTransportInterface
 *
 * A transport that can deliver a response body incrementally. Separate from
 * TransportInterface so existing transports and test doubles keep working;
 * HttpRequest::stream() requires it.
 *
 * @package EzPhp\HttpClient
 */
interface StreamingTransportInterface extends TransportInterface
{
    /**
     * Open a request and return once the final response headers arrived.
     *
     * @param string                $method             HTTP verb.
     * @param string                $url                Fully qualified URL.
     * @param array<string, string> $headers            Request headers.
     * @param string                $body               Raw request body.
     * @param int                   $idleTimeoutSeconds Maximum time without any received data.
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
    ): HttpStream;
}
