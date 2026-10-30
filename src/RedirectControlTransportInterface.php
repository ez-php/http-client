<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Interface RedirectControlTransportInterface
 *
 * Optional capability of a transport: not following HTTP redirects.
 * HttpRequest::withoutRedirects() uses it and, unlike a connect timeout, refuses
 * to send through a transport without it — a caller that asks for no redirects
 * does so for safety, so silently following them would be wrong. Kept apart from
 * TransportInterface so adding it does not break existing implementations.
 *
 * @package EzPhp\HttpClient
 */
interface RedirectControlTransportInterface
{
    /**
     * Return a transport that hands a 3xx response back instead of following it.
     *
     * @return TransportInterface
     */
    public function withoutRedirects(): TransportInterface;
}
