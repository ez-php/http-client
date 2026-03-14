<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class Http
 *
 * Static facade for HttpClient.
 *
 * Without a service provider, a default client backed by CurlTransport is
 * created lazily on first use. When the HttpClientServiceProvider is registered,
 * it wires the container-managed instance here via Http::setClient().
 *
 * Usage:
 *
 *   Http::get('https://api.example.com/users')
 *       ->withHeaders(['Authorization' => 'Bearer token'])
 *       ->json();
 *
 *   Http::post('https://api.example.com/users')
 *       ->withJson(['name' => 'Alice'])
 *       ->status();
 *
 * @package EzPhp\HttpClient
 */
final class Http
{
    private static ?HttpClient $client = null;

    // ─── Client management ───────────────────────────────────────────────────

    /**
     * @param HttpClient $client
     *
     * @return void
     */
    public static function setClient(HttpClient $client): void
    {
        self::$client = $client;
    }

    /**
     * @return HttpClient
     */
    public static function getClient(): HttpClient
    {
        if (self::$client === null) {
            self::$client = new HttpClient(new CurlTransport());
        }

        return self::$client;
    }

    /**
     * Reset the static client (useful in tests).
     */
    public static function resetClient(): void
    {
        self::$client = null;
    }

    // ─── Static facade ───────────────────────────────────────────────────────

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public static function get(string $url): HttpRequest
    {
        return self::getClient()->get($url);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public static function post(string $url): HttpRequest
    {
        return self::getClient()->post($url);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public static function put(string $url): HttpRequest
    {
        return self::getClient()->put($url);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public static function patch(string $url): HttpRequest
    {
        return self::getClient()->patch($url);
    }

    /**
     * @param string $url
     *
     * @return HttpRequest
     */
    public static function delete(string $url): HttpRequest
    {
        return self::getClient()->delete($url);
    }
}
