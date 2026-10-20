<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\HttpStream;
use EzPhp\HttpClient\RequestHeaders;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * FakeTransport::sequence(): ordered fixtures, recorded requests, loud overrun.
 *
 * @package Tests
 */
#[CoversClass(FakeTransport::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(HttpStream::class)]
#[UsesClass(HttpClientException::class)]
#[UsesClass(RequestHeaders::class)]
final class FakeTransportSequenceTest extends TestCase
{
    public function test_the_same_url_answers_in_queue_order(): void
    {
        $fake = FakeTransport::sequence([new HttpResponse(401, 'expired'), new HttpResponse(200, 'ok')]);
        $client = new HttpClient($fake);

        $this->assertSame(401, $client->get('https://api.test/me')->status());
        $this->assertSame(200, $client->get('https://api.test/me')->status());
        $this->assertSame(0, $fake->pending());
        $this->assertCount(2, $fake->getRecorded());
    }

    public function test_queued_exception_is_thrown_in_turn(): void
    {
        $fake = FakeTransport::sequence([new HttpClientException('timeout'), new HttpResponse(200, 'ok')]);
        $client = new HttpClient($fake);

        try {
            $client->get('https://api.test')->send();
            $this->fail('HttpClientException expected');
        } catch (HttpClientException $e) {
            $this->assertSame('timeout', $e->getMessage());
        }

        $this->assertSame('ok', $client->get('https://api.test')->body());
    }

    public function test_an_extra_request_fails_loudly_and_is_not_an_http_client_exception(): void
    {
        $client = new HttpClient(FakeTransport::sequence([new HttpResponse(200, '')]));
        $client->get('https://api.test/1')->send();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unexpected request #2 POST https://api.test/2');

        $client->post('https://api.test/2')->send();
    }

    public function test_sequence_works_for_streams_too(): void
    {
        $fake = FakeTransport::sequence([HttpStream::fake(['a', 'b'])]);

        $this->assertSame('ab', (new HttpClient($fake))->get('https://api.test/s')->stream()->body());
        $this->assertSame(0, $fake->pending());
    }

    public function test_url_map_mode_has_nothing_pending(): void
    {
        $this->assertSame(0, (new FakeTransport(['*' => new HttpResponse(200, '')]))->pending());
    }
}
