<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\CurlStreamHandle;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\HttpStreamException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Class CurlTransportStreamTest
 *
 * Real transfers against a `php -S` server on 127.0.0.1 — no internet access
 * needed, so this runs in the default suite (tests/Integration stays reserved
 * for httpbin). PHP_CLI_SERVER_WORKERS=4 keeps a hanging endpoint from
 * blocking the next test; the built-in server is single-threaded otherwise.
 *
 * @package Tests\HttpClient
 */
#[CoversClass(CurlTransport::class)]
#[CoversClass(CurlStreamHandle::class)]
#[UsesClass(HttpStream::class)]
#[UsesClass(HttpStreamException::class)]
#[UsesClass(HttpClientException::class)]
final class CurlTransportStreamTest extends TestCase
{
    /**
     * @var resource|null
     */
    private static mixed $server = null;

    private static int $port = 0;

    private static string $markerDir = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$port = self::freePort();
        self::$markerDir = sys_get_temp_dir() . '/ez-http-stream-' . bin2hex(random_bytes(4));

        if (!mkdir(self::$markerDir) && !is_dir(self::$markerDir)) {
            throw new RuntimeException('Could not create ' . self::$markerDir);
        }

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Support/stream-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4', 'STREAM_TEST_MARKER_DIR' => self::$markerDir],
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the loopback server.');
        }

        self::$server = $server;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The loopback server did not accept connections within 5 seconds.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;

        foreach (glob(self::$markerDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir(self::$markerDir)) {
            rmdir(self::$markerDir);
        }

        parent::tearDownAfterClass();
    }

    public function test_chunks_arrive_incrementally(): void
    {
        $start = microtime(true);
        $stream = (new CurlTransport())->stream('GET', self::url('/chunks'), [], '', 5);

        $arrivals = [];
        $body = '';

        foreach ($stream as $chunk) {
            $arrivals[] = microtime(true) - $start;
            $body .= $chunk;
        }

        $this->assertSame(200, $stream->status());
        $this->assertStringStartsWith('text/plain', $stream->header('content-type'));
        $this->assertSame("chunk-0\nchunk-1\nchunk-2\n", $body);
        $this->assertCount(3, $arrivals);
        // Two 300 ms pauses on the server: chunks received as they were sent,
        // not all at once when the response finished.
        $this->assertGreaterThanOrEqual(0.5, $arrivals[2] - $arrivals[0]);
    }

    public function test_stream_returns_before_the_body_finishes(): void
    {
        $start = microtime(true);
        $stream = (new CurlTransport())->stream('GET', self::url('/chunks'), [], '', 5);
        $returnedAfter = microtime(true) - $start;

        $this->assertLessThan(0.5, $returnedAfter);
        $this->assertSame("chunk-0\nchunk-1\nchunk-2\n", $stream->body());
    }

    public function test_redirect_reports_the_final_response(): void
    {
        $stream = (new CurlTransport())->stream('GET', self::url('/redirect'), [], '', 5);

        $this->assertSame(200, $stream->status());
        $this->assertSame('chunks', $stream->header('x-stream-test'));
        $this->assertSame('', $stream->header('location'));
        $this->assertSame("chunk-0\nchunk-1\nchunk-2\n", $stream->body());
    }

    public function test_idle_timeout_after_headers_throws_a_stream_exception(): void
    {
        $iterator = (new CurlTransport())->stream('GET', self::url('/hang-after-first-chunk'), [], '', 1)->getIterator();

        $this->assertSame("first\n", $iterator->current());

        $this->expectException(HttpStreamException::class);
        $this->expectExceptionMessage('Stream idle for 1 seconds.');

        $iterator->next();
    }

    public function test_no_headers_within_the_idle_timeout_throws_a_client_exception(): void
    {
        try {
            (new CurlTransport())->stream('GET', self::url('/hang-before-headers'), [], '', 1);
            $this->fail('Expected HttpClientException.');
        } catch (HttpClientException $e) {
            $this->assertSame(HttpClientException::class, $e::class);
            $this->assertSame('No response headers within 1 seconds.', $e->getMessage());
        }
    }

    public function test_connection_lost_mid_body_throws_a_stream_exception(): void
    {
        $stream = (new CurlTransport())->stream('GET', self::url('/drop'), [], '', 5);

        $this->expectException(HttpStreamException::class);
        $this->expectExceptionMessage('Connection lost:');

        $stream->body();
    }

    public function test_error_status_is_not_an_exception(): void
    {
        $stream = (new CurlTransport())->stream('GET', self::url('/error'), [], '', 5);

        $this->assertSame(500, $stream->status());
        $this->assertFalse($stream->ok());
        $this->assertSame('server exploded', $stream->body());
    }

    public function test_close_aborts_the_connection(): void
    {
        $stream = (new CurlTransport())->stream('GET', self::url('/endless'), [], '', 5);

        $this->assertStringStartsWith("tick\n", $stream->getIterator()->current());

        $stream->close();

        $this->assertServerSawAbort();
    }

    public function test_dropping_a_started_iterator_aborts_the_connection(): void
    {
        $iterator = (new CurlTransport())->stream('GET', self::url('/endless'), [], '', 5)->getIterator();

        $this->assertStringStartsWith("tick\n", $iterator->current());

        unset($iterator);

        $this->assertServerSawAbort();
    }

    public function test_iterating_after_close_ends_the_stream(): void
    {
        $stream = (new CurlTransport())->stream('GET', self::url('/chunks'), [], '', 5);
        $iterator = $stream->getIterator();

        $this->assertSame("chunk-0\n", $iterator->current());

        $stream->close();
        $iterator->next();

        $this->assertFalse($iterator->valid());
    }

    public function test_method_headers_and_body_reach_the_server(): void
    {
        $stream = (new CurlTransport())->stream('post', self::url('/echo'), ['X-Test' => 'yes'], '{"a":1}', 5);

        $this->assertSame(
            ['method' => 'POST', 'header' => 'yes', 'body' => '{"a":1}'],
            json_decode($stream->body(), true),
        );
    }

    public function test_connection_refused_throws_a_client_exception(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('cURL error:');

        (new CurlTransport())->stream('GET', 'http://127.0.0.1:' . self::freePort() . '/', [], '', 2);
    }

    public function test_empty_url_throws(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('URL cannot be empty.');

        (new CurlTransport())->stream('GET', '', [], '', 5);
    }

    /**
     * Wait for the /endless handler's shutdown marker and assert the client aborted.
     *
     * @return void
     */
    private function assertServerSawAbort(): void
    {
        $marker = self::$markerDir . '/endless-finished';
        $deadline = microtime(true) + 3;

        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertFileExists($marker);
        $this->assertSame((string) CONNECTION_ABORTED, file_get_contents($marker));

        unlink($marker);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    /**
     * A port nothing listens on at the moment of the call.
     *
     * @return int
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
