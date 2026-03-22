<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class FakeTransportTest
 *
 * Tests Http::fake(), Http::response(), Http::assertSent(), Http::assertNotSent(),
 * and FakeTransport directly.
 *
 * @package Tests
 */
#[CoversClass(FakeTransport::class)]
#[CoversClass(Http::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
final class FakeTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::resetClient();
    }

    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    // ─── Http::response() ─────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_response_helper_creates_response_with_correct_status(): void
    {
        $response = Http::response('body', 201);

        $this->assertSame(201, $response->status());
        $this->assertSame('body', $response->body());
    }

    /**
     * @return void
     */
    public function test_response_helper_json_encodes_array_body(): void
    {
        $response = Http::response(['id' => 42], 200);

        $this->assertSame('{"id":42}', $response->body());
        $this->assertSame('application/json', $response->header('content-type'));
    }

    /**
     * @return void
     */
    public function test_response_helper_passes_headers(): void
    {
        $response = Http::response('ok', 200, ['X-Custom' => 'value']);

        $this->assertSame('value', $response->header('x-custom'));
    }

    // ─── Http::fake() — basic matching ───────────────────────────────────────

    /**
     * @return void
     */
    public function test_fake_star_wildcard_matches_any_url(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $response = Http::get('https://api.example.com/anything')->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(['ok' => true], $response->json());
    }

    /**
     * @return void
     */
    public function test_fake_returns_default_200_when_no_pattern_matches(): void
    {
        Http::fake(['https://other.example.com/*' => Http::response('other', 200)]);

        $response = Http::get('https://api.example.com/users')->send();

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    /**
     * @return void
     */
    public function test_fake_prefix_wildcard_matches_url(): void
    {
        Http::fake(['https://api.example.com/*' => Http::response(['users' => []], 200)]);

        $response = Http::get('https://api.example.com/users')->send();

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test_fake_throws_exception_when_stub_is_exception(): void
    {
        Http::fake(['*' => new HttpClientException('Network error')]);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('Network error');

        Http::get('https://api.example.com/users')->send();
    }

    /**
     * @return void
     */
    public function test_fake_without_arguments_returns_empty_200_for_any_url(): void
    {
        Http::fake();

        $response = Http::post('https://api.example.com/users')
            ->withJson(['name' => 'Alice'])
            ->send();

        $this->assertSame(200, $response->status());
    }

    // ─── Http::assertSent() ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_assert_sent_passes_when_request_matches(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users')->send();

        Http::assertSent(fn ($method, $url) => $method === 'GET' && $url === 'https://api.example.com/users');
        $this->addToAssertionCount(1); // passes = assertSent did not throw
    }

    /**
     * @return void
     */
    public function test_assert_sent_fails_when_no_request_matches(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users')->send();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assertSent() failed');

        Http::assertSent(fn ($method, $url) => $url === 'https://api.example.com/orders');
    }

    /**
     * @return void
     */
    public function test_assert_sent_throws_when_fake_not_installed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires Http::fake()');

        Http::assertSent(fn () => true);
    }

    // ─── Http::assertNotSent() ────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_assert_not_sent_passes_when_no_matching_request(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users')->send();

        Http::assertNotSent(fn ($method, $url) => $url === 'https://api.example.com/orders');
        $this->addToAssertionCount(1); // passes = assertNotSent did not throw
    }

    /**
     * @return void
     */
    public function test_assert_not_sent_fails_when_matching_request_found(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users')->send();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assertNotSent() failed');

        Http::assertNotSent(fn ($method, $url) => $url === 'https://api.example.com/users');
    }

    /**
     * @return void
     */
    public function test_assert_not_sent_throws_when_fake_not_installed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires Http::fake()');

        Http::assertNotSent(fn () => false);
    }

    // ─── FakeTransport::getRecorded() ─────────────────────────────────────────

    /**
     * @return void
     */
    public function test_fake_records_all_requests_in_order(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        Http::get('https://api.example.com/a')->send();
        Http::post('https://api.example.com/b')->withJson(['x' => 1])->send();

        $recorded = (new \ReflectionProperty(Http::class, 'fakeTransport'))
            ->getValue(null);

        $this->assertInstanceOf(FakeTransport::class, $recorded);

        /** @var FakeTransport $fake */
        $fake = $recorded;
        $requests = $fake->getRecorded();

        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('https://api.example.com/a', $requests[0]['url']);
        $this->assertSame('POST', $requests[1]['method']);
    }

    // ─── resetClient() clears fake ────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_reset_client_clears_fake_transport(): void
    {
        Http::fake();
        Http::resetClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires Http::fake()');

        Http::assertSent(fn () => true);
    }
}
