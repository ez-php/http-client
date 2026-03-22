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
 * Testing:
 *
 *   Http::fake(['*' => Http::response(['ok' => true], 200)]);
 *   Http::get('https://api.example.com/ping')->send();
 *   Http::assertSent(fn($m, $u) => $m === 'GET' && str_contains($u, 'ping'));
 *
 * @package EzPhp\HttpClient
 */
final class Http
{
    private static ?HttpClient $client = null;

    private static ?FakeTransport $fakeTransport = null;

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
        self::$fakeTransport = null;
    }

    // ─── Fake transport (item 21) ─────────────────────────────────────────────

    /**
     * Install a fake transport that returns pre-configured responses without
     * making real network requests.
     *
     * Keys are URL patterns (fnmatch syntax), values are HttpResponse instances
     * created with Http::response() or HttpClientException instances.
     *
     * Examples:
     *
     *   Http::fake();  // all requests return 200 OK empty response
     *
     *   Http::fake([
     *       '*'                              => Http::response(['ok' => true], 200),
     *       'https://api.example.com/users*' => Http::response(['id' => 1], 201),
     *   ]);
     *
     * @param array<string, HttpResponse|HttpClientException> $responses
     *
     * @return void
     */
    public static function fake(array $responses = []): void
    {
        self::$fakeTransport = new FakeTransport($responses);
        self::$client = new HttpClient(self::$fakeTransport);
    }

    /**
     * Create an HttpResponse for use with Http::fake().
     *
     * @param array<string, mixed>|string $body     Array is JSON-encoded automatically.
     * @param int                         $status   HTTP status code (default 200).
     * @param array<string, string>       $headers  Response headers (lowercase names).
     *
     * @return HttpResponse
     */
    public static function response(array|string $body = '', int $status = 200, array $headers = []): HttpResponse
    {
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);

        if (is_array($body)) {
            $rawBody = (string) json_encode($body);
            $normalizedHeaders['content-type'] = 'application/json';
        } else {
            $rawBody = $body;
        }

        return new HttpResponse($status, $rawBody, $normalizedHeaders);
    }

    /**
     * Assert that at least one request matching the callback was sent.
     * Requires Http::fake() to have been called first.
     *
     * @param callable(string $method, string $url, array<string, string> $headers, string $body): bool $callback
     *
     * @return void
     * @throws \RuntimeException When no matching request is found or fake is not installed.
     */
    public static function assertSent(callable $callback): void
    {
        if (self::$fakeTransport === null) {
            throw new \RuntimeException('Http::assertSent() requires Http::fake() to be called first.');
        }

        foreach (self::$fakeTransport->getRecorded() as $req) {
            if ($callback($req['method'], $req['url'], $req['headers'], $req['body'])) {
                return;
            }
        }

        throw new \RuntimeException('Http::assertSent() failed: no matching request was found.');
    }

    /**
     * Assert that no request matching the callback was sent.
     * Requires Http::fake() to have been called first.
     *
     * @param callable(string $method, string $url, array<string, string> $headers, string $body): bool $callback
     *
     * @return void
     * @throws \RuntimeException When a matching request is found or fake is not installed.
     */
    public static function assertNotSent(callable $callback): void
    {
        if (self::$fakeTransport === null) {
            throw new \RuntimeException('Http::assertNotSent() requires Http::fake() to be called first.');
        }

        foreach (self::$fakeTransport->getRecorded() as $req) {
            if ($callback($req['method'], $req['url'], $req['headers'], $req['body'])) {
                throw new \RuntimeException('Http::assertNotSent() failed: a matching request was found.');
            }
        }
    }

    // ─── Pool / async (item 25) ───────────────────────────────────────────────

    /**
     * Execute a batch of requests concurrently.
     *
     * The callback receives a Pool and must return an array of PooledRequest
     * instances (created via $pool->get(), $pool->post(), etc.).
     * Responses are returned in the same order as the requests.
     *
     * Example:
     *
     *   $responses = Http::pool(fn($pool) => [
     *       $pool->get('https://api.example.com/a'),
     *       $pool->get('https://api.example.com/b'),
     *   ]);
     *
     * @param \Closure(Pool): list<PooledRequest> $callback
     *
     * @return list<HttpResponse>
     * @throws HttpClientException On transport failure.
     */
    public static function pool(\Closure $callback): array
    {
        $pool = new Pool(self::getClient()->getTransport());
        $requests = $callback($pool);

        return $pool->execute($requests);
    }

    /**
     * Return a Pool backed by the current transport.
     *
     * Useful for building concurrent request groups manually:
     *
     *   $pool = Http::async();
     *   $req1 = $pool->get($url1);
     *   $req2 = $pool->post($url2)->withJson($data);
     *   [$r1, $r2] = $pool->wait();
     *
     * @return Pool
     */
    public static function async(): Pool
    {
        return new Pool(self::getClient()->getTransport());
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
