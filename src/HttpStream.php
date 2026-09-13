<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use Closure;
use Generator;
use IteratorAggregate;
use Throwable;

/**
 * Class HttpStream
 *
 * A response whose status and headers are known but whose body is still
 * being received. Iterating yields raw chunks as they arrive — a chunk has no
 * framing guarantee, so a line or an SSE event may span several chunks.
 *
 * Single use: the chunk generator cannot be rewound. The underlying
 * connection is closed when the body is exhausted, on close(), or when the
 * object is destroyed.
 *
 * Usage:
 *
 *   $stream = Http::post($url)->withJson($payload)->stream();
 *
 *   if (!$stream->ok()) {
 *       throw new RuntimeException($stream->body());
 *   }
 *
 *   foreach ($stream as $chunk) { ... }
 *
 * @implements IteratorAggregate<int, string>
 *
 * @package EzPhp\HttpClient
 */
final class HttpStream implements IteratorAggregate
{
    /**
     * @var array<string, string>
     */
    private readonly array $headers;

    /**
     * @var Generator<int, string, void, void>|null
     */
    private ?Generator $generator = null;

    private bool $closed = false;

    /**
     * HttpStream Constructor
     *
     * @param int                                             $statusCode
     * @param array<string, string>                           $headers    Any case; normalised to lowercase.
     * @param (Closure(): Generator<int, string, void, void>) $chunks     Started on first iteration.
     * @param (Closure(): void)|null                          $onClose    Aborts the underlying transfer.
     */
    public function __construct(
        private readonly int $statusCode,
        array $headers,
        private readonly Closure $chunks,
        private readonly ?Closure $onClose = null,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * Build a stream for FakeTransport / Http::fake().
     *
     * A Throwable in $chunks is thrown when the iterator reaches its position,
     * which simulates a failure in the middle of the body.
     *
     * @param list<string|Throwable> $chunks
     * @param int                    $status
     * @param array<string, string>  $headers Any case; normalised to lowercase.
     *
     * @return self
     */
    public static function fake(array $chunks = [], int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, static function () use ($chunks): Generator {
            yield from self::fakeChunks($chunks);
        });
    }

    /**
     * @return int
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns true when the status code indicates success (2xx).
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @param string $name    Header name (case-insensitive).
     * @param string $default Returned when the header is absent.
     *
     * @return string
     */
    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The raw body chunks as they arrive. Returns the same generator on every call.
     *
     * @return Generator<int, string, void, void>
     * @throws HttpStreamException When the transfer fails mid-body.
     */
    public function getIterator(): Generator
    {
        if ($this->generator === null) {
            $this->generator = ($this->chunks)();
        }

        return $this->generator;
    }

    /**
     * Read the remaining body into one string. Intended for error responses.
     *
     * @return string
     * @throws HttpStreamException When the transfer fails mid-body.
     */
    public function body(): string
    {
        $generator = $this->getIterator();
        $body = '';

        // valid()/next() rather than foreach: foreach rewinds, and rewinding
        // an already-advanced generator throws.
        while ($generator->valid()) {
            $body .= $generator->current();
            $generator->next();
        }

        return $body;
    }

    /**
     * Abort the underlying transfer. Idempotent.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->onClose !== null) {
            ($this->onClose)();
        }
    }

    /**
     * Close the connection when the stream is dropped without being iterated.
     *
     * Once getIterator() was called, the generator owns the connection: it may
     * outlive this object (`$it = $http->stream()->getIterator()`), and the
     * transport closes the connection when the generator finishes or is destroyed.
     */
    public function __destruct()
    {
        if ($this->generator === null) {
            $this->close();
        }
    }

    /**
     * @param list<string|Throwable> $chunks
     *
     * @return Generator<int, string, void, void>
     */
    private static function fakeChunks(array $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            if ($chunk instanceof Throwable) {
                throw $chunk;
            }

            yield $chunk;
        }
    }
}
