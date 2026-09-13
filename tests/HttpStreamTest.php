<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class HttpStreamTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpStream::class)]
#[UsesClass(HttpStreamException::class)]
final class HttpStreamTest extends TestCase
{
    public function test_status_and_headers_are_normalised(): void
    {
        $stream = HttpStream::fake([], 201, ['Content-Type' => 'text/event-stream']);

        $this->assertSame(201, $stream->status());
        $this->assertSame(['content-type' => 'text/event-stream'], $stream->headers());
        $this->assertSame('text/event-stream', $stream->header('CONTENT-TYPE'));
        $this->assertSame('fallback', $stream->header('x-missing', 'fallback'));
    }

    public function test_ok_is_true_only_for_2xx(): void
    {
        $this->assertFalse(HttpStream::fake([], 199)->ok());
        $this->assertTrue(HttpStream::fake([], 200)->ok());
        $this->assertTrue(HttpStream::fake([], 299)->ok());
        $this->assertFalse(HttpStream::fake([], 300)->ok());
    }

    public function test_iterates_chunks_in_order(): void
    {
        $stream = HttpStream::fake(['a', 'b', 'c']);

        $this->assertSame(['a', 'b', 'c'], iterator_to_array($stream, false));
    }

    public function test_get_iterator_returns_the_same_generator(): void
    {
        $stream = HttpStream::fake(['a']);

        $this->assertSame($stream->getIterator(), $stream->getIterator());
    }

    public function test_body_drains_the_remaining_chunks(): void
    {
        $stream = HttpStream::fake(['first', 'second', 'third']);
        $iterator = $stream->getIterator();

        $this->assertSame('first', $iterator->current());
        $iterator->next();

        $this->assertSame('secondthird', $stream->body());
    }

    public function test_fake_throws_a_throwable_at_its_position(): void
    {
        $iterator = HttpStream::fake(['ok', new HttpStreamException('reset')])->getIterator();

        $this->assertSame('ok', $iterator->current());

        $this->expectException(HttpStreamException::class);
        $this->expectExceptionMessage('reset');

        $iterator->next();
    }

    public function test_close_invokes_the_hook_once(): void
    {
        $calls = 0;
        $stream = new HttpStream(200, [], self::noChunks(...), static function () use (&$calls): void {
            $calls++;
        });

        $stream->close();
        $stream->close();

        $this->assertSame(1, $calls);
    }

    public function test_destructor_closes_the_stream(): void
    {
        $calls = 0;
        $stream = new HttpStream(200, [], self::noChunks(...), static function () use (&$calls): void {
            $calls++;
        });

        unset($stream);

        $this->assertSame(1, $calls);
    }

    public function test_destructor_leaves_a_started_iterator_usable(): void
    {
        $calls = 0;
        $iterator = (new HttpStream(200, [], static function (): \Generator {
            yield 'a';
            yield 'b';
        }, static function () use (&$calls): void {
            $calls++;
        }))->getIterator();

        $this->assertSame(0, $calls);
        $this->assertSame(['a', 'b'], iterator_to_array($iterator, false));
    }

    /**
     * @return \Generator<int, string, void, void>
     */
    private static function noChunks(): \Generator
    {
        yield from [];
    }
}
