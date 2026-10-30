<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\HttpClient\CircuitBreakerTransport;
use EzPhp\HttpClient\CurlHeaders;
use EzPhp\HttpClient\CurlStreamHandle;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\Pool;
use EzPhp\HttpClient\PooledRequest;
use EzPhp\HttpClient\RequestHeaders;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * HttpRequest::withoutRedirects() against a loopback server, and the http/https
 * protocol allow-list of the cURL transports.
 *
 * @package Tests
 */
#[CoversClass(HttpRequest::class)]
#[CoversClass(CurlTransport::class)]
#[CoversClass(CurlStreamHandle::class)]
#[CoversClass(FakeTransport::class)]
#[CoversClass(CircuitBreakerTransport::class)]
#[CoversClass(Pool::class)]
#[UsesClass(CurlHeaders::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpClientException::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(HttpStream::class)]
#[UsesClass(PooledRequest::class)]
#[UsesClass(RequestHeaders::class)]
final class WithoutRedirectsTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $markerDir = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$markerDir = sys_get_temp_dir() . '/ez-redirect-' . bin2hex(random_bytes(4));
        mkdir(self::$markerDir);

        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            throw new RuntimeException('Could not reserve a loopback port.');
        }

        $name = (string) stream_socket_get_name($probe, false);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Support/redirect-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['REDIRECT_TEST_MARKER_DIR' => self::$markerDir],
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the loopback server.');
        }

        self::$server = $server;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The loopback server did not come up.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        @unlink(self::$markerDir . '/target-hit');
        @rmdir(self::$markerDir);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(self::$markerDir . '/target-hit');
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function test_redirects_are_followed_by_default(): void
    {
        $response = (new HttpClient(new CurlTransport()))->get($this->url('/hop'))->send();

        $this->assertSame(200, $response->status());
        $this->assertFileExists(self::$markerDir . '/target-hit');
    }

    public function test_send_without_redirects_returns_the_3xx_and_never_requests_the_target(): void
    {
        $response = (new HttpClient(new CurlTransport()))->post($this->url('/hop'))->withoutRedirects()->send();

        $this->assertSame(302, $response->status());
        $this->assertFileDoesNotExist(self::$markerDir . '/target-hit');
    }

    public function test_stream_without_redirects_returns_the_3xx_and_never_requests_the_target(): void
    {
        $stream = (new HttpClient(new CurlTransport()))->get($this->url('/hop'))->withoutRedirects()->stream();
        $status = $stream->status();
        $stream->close();

        $this->assertSame(302, $status);
        $this->assertFileDoesNotExist(self::$markerDir . '/target-hit');
    }

    public function test_send_rejects_a_non_http_scheme(): void
    {
        $this->expectException(HttpClientException::class);

        (new CurlTransport())->send('GET', 'file:///etc/hostname', [], '');
    }

    public function test_send_does_not_follow_a_redirect_to_a_non_http_scheme(): void
    {
        $this->expectException(HttpClientException::class);

        (new CurlTransport())->send('GET', $this->url('/file-hop'), [], '');
    }

    public function test_pool_rejects_a_non_http_scheme(): void
    {
        $this->expectException(HttpClientException::class);

        $pool = new Pool(new CurlTransport());
        $pool->get('file:///etc/hostname');
        $pool->wait();
    }

    public function test_pool_over_a_redirect_free_transport_does_not_follow_redirects(): void
    {
        $pool = new Pool((new CurlTransport())->withoutRedirects());
        $request = $pool->get($this->url('/hop'));
        $pool->wait();

        $this->assertSame(302, $request->response()->status());
        $this->assertFileDoesNotExist(self::$markerDir . '/target-hit');
    }

    public function test_fake_transport_records_the_opt_out_for_one_request(): void
    {
        $fake = new FakeTransport();
        $client = new HttpClient($fake);

        $client->get('https://api.test/a')->withoutRedirects()->send();
        $client->get('https://api.test/b')->send();

        $recorded = $fake->getRecorded();
        $this->assertFalse($recorded[0]['followRedirects']);
        $this->assertTrue($recorded[1]['followRedirects']);
    }

    public function test_circuit_breaker_passes_the_opt_out_to_the_wrapped_transport(): void
    {
        $fake = new FakeTransport();

        (new HttpClient(new CircuitBreakerTransport($fake, new ArrayDriver())))
            ->get('https://api.test/a')->withoutRedirects()->send();

        $this->assertFalse($fake->getRecorded()[0]['followRedirects']);
    }

    public function test_a_transport_without_the_capability_fails_closed(): void
    {
        $plain = new class () implements TransportInterface {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
            {
                $this->calls++;

                return new HttpResponse(200, '');
            }
        };

        try {
            (new HttpClient($plain))->get('https://api.test/a')->withoutRedirects()->send();
            $this->fail('expected HttpClientException');
        } catch (HttpClientException) {
            $this->assertSame(0, $plain->calls, 'nothing may be sent when redirects cannot be disabled');
        }
    }
}
