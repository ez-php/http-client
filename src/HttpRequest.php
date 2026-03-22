<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class HttpRequest
 *
 * Fluent builder for a pending HTTP request.
 * Constructed by HttpClient; not instantiated directly by application code.
 *
 * Usage:
 *
 *   Http::get('https://api.example.com/users')
 *       ->withHeaders(['Authorization' => 'Bearer token'])
 *       ->retry(3, 200)
 *       ->json();
 *
 * @package EzPhp\HttpClient
 */
final class HttpRequest
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    private string $body = '';

    /**
     * @var list<array{name: string, contents: string, filename: string, mimeType: string}>
     */
    private array $attachments = [];

    /**
     * @var list<\Closure(\Closure(): HttpResponse): HttpResponse>
     */
    private array $middleware = [];

    private int $retryTimes = 0;

    private int $retrySleepMs = 0;

    /**
     * @var (\Closure(HttpResponse): bool)|null
     */
    private ?\Closure $retryWhen = null;

    /**
     * HttpRequest Constructor
     *
     * @param string             $method
     * @param string             $url
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly TransportInterface $transport,
    ) {
    }

    // ─── Builder — headers ────────────────────────────────────────────────────

    /**
     * Merge additional headers into the request.
     *
     * @param array<string, string> $headers
     *
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone($this, [
            'headers' => array_merge($this->headers, $headers),
        ]);

        return $clone;
    }

    /**
     * Set a single request header.
     *
     * @param string $name
     * @param string $value
     *
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    // ─── Builder — body ───────────────────────────────────────────────────────

    /**
     * Set a raw string body.
     *
     * @param string $body
     *
     * @return self
     */
    public function withBody(string $body): self
    {
        $clone = clone($this, [
            'body' => $body,
        ]);

        return $clone;
    }

    /**
     * Set a JSON-encoded body and add the appropriate Content-Type header.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public function withJson(array $data): self
    {
        $clone = clone $this;
        $clone->body = (string) json_encode($data);
        $clone->headers['Content-Type'] = 'application/json';

        return $clone;
    }

    /**
     * Set a form-encoded body and add the appropriate Content-Type header.
     *
     * @param array<string, mixed> $data
     *
     * @return self
     */
    public function withForm(array $data): self
    {
        $clone = clone $this;
        $clone->body = http_build_query($data);
        $clone->headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $clone;
    }

    // ─── Builder — multipart (item 23) ───────────────────────────────────────

    /**
     * Attach a file or binary field for multipart/form-data upload.
     *
     * When at least one attachment is added the request body is serialised as
     * multipart/form-data, overriding any previously set body.
     *
     * Example:
     *
     *   Http::post($url)
     *       ->attach('report', file_get_contents('/tmp/r.pdf'), 'report.pdf', 'application/pdf')
     *       ->send();
     *
     * @param string $name      Form field name.
     * @param string $contents  Raw file / field contents.
     * @param string $filename  Filename sent in the Content-Disposition header.
     * @param string $mimeType  MIME type for the Content-Type part header.
     *
     * @return self
     */
    public function attach(
        string $name,
        string $contents,
        string $filename = '',
        string $mimeType = 'application/octet-stream',
    ): self {
        $clone = clone $this;
        $clone->attachments[] = [
            'name' => $name,
            'contents' => $contents,
            'filename' => $filename,
            'mimeType' => $mimeType,
        ];

        return $clone;
    }

    // ─── Builder — middleware (item 24) ──────────────────────────────────────

    /**
     * Add a middleware closure to the request pipeline.
     *
     * Middleware is executed in the order it is added (outermost first).
     * Each closure receives the next callable in the chain and must return an HttpResponse.
     *
     * Example:
     *
     *   Http::get($url)
     *       ->withMiddleware(function (\Closure $next): HttpResponse {
     *           $response = $next();
     *           // log, modify, or inspect $response
     *           return $response;
     *       })
     *       ->send();
     *
     * @param \Closure(\Closure(): HttpResponse): HttpResponse $middleware
     *
     * @return self
     */
    public function withMiddleware(\Closure $middleware): self
    {
        $clone = clone $this;
        $clone->middleware[] = $middleware;

        return $clone;
    }

    // ─── Builder — retry (item 22) ───────────────────────────────────────────

    /**
     * Retry the request on failure.
     *
     * By default, retries on 5xx responses and HttpClientException.
     * Pass a custom $when closure to control retry conditions based on the response.
     *
     * Example:
     *
     *   Http::get($url)
     *       ->retry(3, 100, fn($r) => $r->status() >= 500)
     *       ->send();
     *
     * @param int                                    $times   Maximum number of additional attempts.
     * @param int                                    $sleepMs Milliseconds to wait between attempts.
     * @param (\Closure(HttpResponse): bool)|null    $when    Custom retry condition; null = retry on 5xx.
     *
     * @return self
     */
    public function retry(int $times, int $sleepMs = 100, ?\Closure $when = null): self
    {
        $clone = clone $this;
        $clone->retryTimes = $times;
        $clone->retrySleepMs = $sleepMs;
        $clone->retryWhen = $when;

        return $clone;
    }

    // ─── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Send the request and return the full response object.
     *
     * @return HttpResponse
     * @throws HttpClientException
     */
    public function send(): HttpResponse
    {
        // Resolve effective headers and body.
        $headers = $this->headers;
        $body = $this->body;

        if ($this->attachments !== []) {
            ['body' => $body, 'contentType' => $contentType] = $this->buildMultipart();
            $headers['Content-Type'] = $contentType;
        }

        $transport = $this->transport;
        $method = $this->method;
        $url = $this->url;

        // Core dispatch closure.
        /** @var \Closure(): HttpResponse $dispatch */
        $dispatch = static function () use ($transport, $method, $url, $headers, $body): HttpResponse {
            return $transport->send($method, $url, $headers, $body);
        };

        // Wrap with middleware (outermost added first).
        foreach (array_reverse($this->middleware) as $mw) {
            $inner = $dispatch;
            $dispatch = static function () use ($mw, $inner): HttpResponse {
                return $mw($inner);
            };
        }

        // Execute without retry.
        if ($this->retryTimes === 0) {
            return $dispatch();
        }

        // Execute with retry.
        $maxAttempts = $this->retryTimes + 1;
        $lastException = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0 && $this->retrySleepMs > 0) {
                usleep($this->retrySleepMs * 1000);
            }

            try {
                $response = $dispatch();

                $isLastAttempt = ($attempt >= $this->retryTimes);

                if (!$isLastAttempt) {
                    $when = $this->retryWhen;
                    $shouldRetry = $when !== null ? $when($response) : $response->status() >= 500;

                    if ($shouldRetry) {
                        continue;
                    }
                }

                return $response;
            } catch (HttpClientException $e) {
                $lastException = $e;

                if ($attempt + 1 >= $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new HttpClientException('All retry attempts exhausted.');
    }

    /**
     * Send and return the decoded JSON body.
     *
     * @return mixed
     * @throws HttpClientException
     */
    public function json(): mixed
    {
        return $this->send()->json();
    }

    /**
     * Send and return the raw response body.
     *
     * @return string
     * @throws HttpClientException
     */
    public function body(): string
    {
        return $this->send()->body();
    }

    /**
     * Send and return the HTTP status code.
     *
     * @return int
     * @throws HttpClientException
     */
    public function status(): int
    {
        return $this->send()->status();
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a multipart/form-data body from the current attachments.
     *
     * @return array{body: string, contentType: string}
     */
    private function buildMultipart(): array
    {
        $boundary = '----EzPhpFormBoundary' . bin2hex(random_bytes(8));
        $body = '';

        foreach ($this->attachments as $part) {
            $body .= "--{$boundary}\r\n";

            $disposition = "Content-Disposition: form-data; name=\"{$part['name']}\"";

            if ($part['filename'] !== '') {
                $disposition .= "; filename=\"{$part['filename']}\"";
            }

            $body .= $disposition . "\r\n";
            $body .= "Content-Type: {$part['mimeType']}\r\n\r\n";
            $body .= $part['contents'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return [
            'body' => $body,
            'contentType' => "multipart/form-data; boundary={$boundary}",
        ];
    }
}
