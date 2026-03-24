<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class Pool
 *
 * Concurrent HTTP request pool.
 *
 * When the underlying transport is CurlTransport, all queued requests are
 * dispatched in parallel via curl_multi_exec. Otherwise they run sequentially
 * through the transport (useful with FakeTransport in tests).
 *
 * Usage via Http::pool():
 *
 *   $responses = Http::pool(fn($pool) => [
 *       $pool->get('https://api.example.com/users'),
 *       $pool->post('https://api.example.com/orders')->withJson(['item' => 1]),
 *   ]);
 *   // $responses[0] = HttpResponse for first request
 *   // $responses[1] = HttpResponse for second request
 *
 * Usage via Http::async():
 *
 *   $pool = Http::async();
 *   $req1 = $pool->get($url1);
 *   $req2 = $pool->post($url2)->withJson($data);
 *   [$r1, $r2] = $pool->wait();   // or use $req1->response() after wait()
 *
 * @package EzPhp\HttpClient
 */
final class Pool
{
    private const int TIMEOUT_SECONDS = 30;

    /**
     * @var list<PooledRequest>
     */
    private array $tracked = [];

    /**
     * Pool Constructor
     *
     * @param TransportInterface $transport
     */
    public function __construct(private readonly TransportInterface $transport)
    {
    }

    /**
     * Queue a GET request.
     *
     * @param string $url
     *
     * @return PooledRequest
     */
    public function get(string $url): PooledRequest
    {
        return $this->track(new PooledRequest('GET', $url));
    }

    /**
     * Queue a POST request.
     *
     * @param string $url
     *
     * @return PooledRequest
     */
    public function post(string $url): PooledRequest
    {
        return $this->track(new PooledRequest('POST', $url));
    }

    /**
     * Queue a PUT request.
     *
     * @param string $url
     *
     * @return PooledRequest
     */
    public function put(string $url): PooledRequest
    {
        return $this->track(new PooledRequest('PUT', $url));
    }

    /**
     * Queue a PATCH request.
     *
     * @param string $url
     *
     * @return PooledRequest
     */
    public function patch(string $url): PooledRequest
    {
        return $this->track(new PooledRequest('PATCH', $url));
    }

    /**
     * Queue a DELETE request.
     *
     * @param string $url
     *
     * @return PooledRequest
     */
    public function delete(string $url): PooledRequest
    {
        return $this->track(new PooledRequest('DELETE', $url));
    }

    /**
     * Execute all tracked requests and return their responses in order.
     * Also resolves each PooledRequest so response() can be called on them.
     *
     * @return list<HttpResponse>
     * @throws HttpClientException On transport failure.
     */
    public function wait(): array
    {
        return $this->execute($this->tracked);
    }

    /**
     * Execute the given requests and return responses in the same order.
     * Also resolves each PooledRequest with its response.
     *
     * @param list<PooledRequest> $requests
     *
     * @return list<HttpResponse>
     * @throws HttpClientException On transport failure.
     */
    public function execute(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $responses = $this->transport instanceof CurlTransport
            ? $this->sendConcurrent($requests)
            : $this->sendSequential($requests);

        foreach ($requests as $i => $req) {
            $req->resolve($responses[$i]);
        }

        return $responses;
    }

    /**
     * Track a PooledRequest internally.
     *
     * @param PooledRequest $request
     *
     * @return PooledRequest
     */
    private function track(PooledRequest $request): PooledRequest
    {
        $this->tracked[] = $request;

        return $request;
    }

    /**
     * Execute requests sequentially through the transport.
     * Used when the transport is not CurlTransport (e.g. FakeTransport in tests).
     *
     * @param list<PooledRequest> $requests
     *
     * @return list<HttpResponse>
     * @throws HttpClientException
     */
    private function sendSequential(array $requests): array
    {
        $responses = [];

        foreach ($requests as $req) {
            $responses[] = $this->transport->send(
                $req->getMethod(),
                $req->getUrl(),
                $req->getHeaders(),
                $req->getBody(),
            );
        }

        return $responses;
    }

    /**
     * Execute requests in parallel via curl_multi_exec.
     * Only called when the transport is CurlTransport.
     *
     * @param list<PooledRequest> $requests
     *
     * @return list<HttpResponse>
     * @throws HttpClientException On cURL failure.
     */
    private function sendConcurrent(array $requests): array
    {
        $mh = curl_multi_init();
        /** @var list<\CurlHandle> $handles */
        $handles = [];

        foreach ($requests as $req) {
            $ch = curl_init();

            if ($ch === false) {
                curl_multi_close($mh);
                throw new HttpClientException('Failed to initialize curl handle.');
            }

            $method = strtoupper($req->getMethod());
            $url = $req->getUrl();
            $headers = $req->getHeaders();
            $body = $req->getBody();

            if ($url === '') {
                curl_multi_close($mh);
                throw new HttpClientException('URL cannot be empty.');
            }

            if ($method === '') {
                curl_multi_close($mh);
                throw new HttpClientException('HTTP method cannot be empty.');
            }

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Execute all handles in parallel.
        do {
            $status = curl_multi_exec($mh, $running);

            if ($running > 0) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];

        foreach ($handles as $ch) {
            $result = curl_multi_getcontent($ch);

            if ($result === null) {
                $error = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_multi_close($mh);
                throw new HttpClientException('cURL error: ' . $error);
            }

            /** @var int $headerSize */
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            /** @var int $statusCode */
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $rawHeaders = substr($result, 0, $headerSize);
            $body = substr($result, $headerSize);

            $responses[] = new HttpResponse($statusCode, $body, $this->parseHeaders($rawHeaders));

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $responses;
    }

    /**
     * Convert an associative headers array to the "Name: value" format curl expects.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    /**
     * Parse the raw header block into an associative array.
     * Header names are normalised to lowercase.
     * When redirects occur, only the last header block is kept.
     *
     * @param string $rawHeaders
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $blocks = array_filter(array_map('trim', explode("\r\n\r\n", $rawHeaders)));
        $lastBlock = end($blocks);

        if ($lastBlock === false) {
            return [];
        }

        $parsed = [];

        foreach (explode("\r\n", $lastBlock) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        return $parsed;
    }
}
