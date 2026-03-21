<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class HttpClient
 *
 * Factory / entry-point for fluent HTTP requests.
 * Inject this class via the container or use the Http static facade.
 *
 * @package EzPhp\HttpClient
 */
final readonly class HttpClient
{
    /**
     * HttpClient Constructor
     *
     * @param TransportInterface $transport
     */
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public function get(string $url): HttpRequest
    {
        return new HttpRequest('GET', $url, $this->transport);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public function post(string $url): HttpRequest
    {
        return new HttpRequest('POST', $url, $this->transport);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public function put(string $url): HttpRequest
    {
        return new HttpRequest('PUT', $url, $this->transport);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public function patch(string $url): HttpRequest
    {
        return new HttpRequest('PATCH', $url, $this->transport);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public function delete(string $url): HttpRequest
    {
        return new HttpRequest('DELETE', $url, $this->transport);
    }
}
