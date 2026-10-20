<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\HttpClient\CircuitBreakerTransport;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\RequestHeaders;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * HttpRequest::withConnectTimeout() reaching the transport.
 *
 * @package Tests
 */
#[CoversClass(HttpRequest::class)]
#[CoversClass(FakeTransport::class)]
#[CoversClass(CircuitBreakerTransport::class)]
#[CoversClass(CurlTransport::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(HttpStream::class)]
#[UsesClass(RequestHeaders::class)]
final class ConnectTimeoutTest extends TestCase
{
    public function test_send_and_stream_hand_the_connect_timeout_to_the_transport(): void
    {
        $fake = new FakeTransport();
        $client = new HttpClient($fake);

        $client->get('https://api.test/a')->withConnectTimeout(3)->send();
        $client->get('https://api.test/b')->send();
        $client->get('https://api.test/c')->withConnectTimeout(2)->stream()->close();

        $recorded = $fake->getRecorded();
        $this->assertSame(3, $recorded[0]['connectTimeoutSeconds']);
        $this->assertNull($recorded[1]['connectTimeoutSeconds'], 'default stays unchanged');
        $this->assertSame(2, $recorded[2]['connectTimeoutSeconds']);
    }

    public function test_circuit_breaker_passes_it_through_to_the_wrapped_transport(): void
    {
        $fake = new FakeTransport();
        $client = new HttpClient(new CircuitBreakerTransport($fake, new ArrayDriver()));

        $client->get('https://api.test/a')->withConnectTimeout(4)->send();

        $this->assertSame(4, $fake->getRecorded()[0]['connectTimeoutSeconds']);
    }

    public function test_transports_without_the_capability_ignore_it(): void
    {
        $plain = new class () implements TransportInterface {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers, string $body, ?int $timeoutSeconds = null): HttpResponse
            {
                $this->calls++;

                return new HttpResponse(204, '');
            }
        };

        $this->assertSame(204, (new HttpClient($plain))->get('https://api.test')->withConnectTimeout(1)->status());
        $this->assertSame(1, $plain->calls);
    }

    public function test_curl_transport_with_connect_timeout_returns_a_configured_copy(): void
    {
        $transport = new CurlTransport();
        $copy = $transport->withConnectTimeout(5);

        $this->assertNotSame($transport, $copy);
        $property = new \ReflectionProperty(CurlTransport::class, 'connectTimeoutSeconds');
        $this->assertNull($property->getValue($transport));
        $this->assertSame(5, $property->getValue($copy));
    }
}
