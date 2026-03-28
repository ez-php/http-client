<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientException;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\Pool;
use EzPhp\HttpClient\PooledRequest;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class PoolTest
 *
 * Tests Pool, PooledRequest, Http::pool(), and Http::async().
 *
 * @package Tests
 */
#[CoversClass(Pool::class)]
#[CoversClass(PooledRequest::class)]
#[CoversClass(Http::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(FakeTransport::class)]
final class PoolTest extends TestCase
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

    // ─── PooledRequest builder ────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_pooled_request_stores_method_and_url(): void
    {
        $req = new PooledRequest('GET', 'https://api.example.com/users');

        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://api.example.com/users', $req->getUrl());
    }

    /**
     * @return void
     */
    public function test_pooled_request_with_json_sets_body_and_header(): void
    {
        $req = (new PooledRequest('POST', 'https://api.example.com/users'))
            ->withJson(['name' => 'Alice']);

        $this->assertSame('{"name":"Alice"}', $req->getBody());
        $this->assertSame('application/json', $req->getHeaders()['Content-Type']);
    }

    /**
     * @return void
     */
    public function test_pooled_request_response_throws_before_execution(): void
    {
        $req = new PooledRequest('GET', 'https://api.example.com');

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('not been executed');

        $req->response();
    }

    /**
     * @return void
     */
    public function test_pooled_request_resolve_sets_response(): void
    {
        $req = new PooledRequest('GET', 'https://api.example.com');
        $response = new HttpResponse(200, 'body');

        $req->resolve($response);

        $this->assertSame($response, $req->response());
    }

    /**
     * @return void
     */
    public function test_pooled_request_is_immutable(): void
    {
        $base = new PooledRequest('GET', 'https://api.example.com');
        $withHdr = $base->withHeader('X-Token', 'abc');

        $this->assertEmpty($base->getHeaders());
        $this->assertSame('abc', $withHdr->getHeaders()['X-Token']);
    }

    // ─── Http::pool() ─────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_pool_returns_responses_in_request_order(): void
    {
        Http::fake([
            'https://api.example.com/a' => Http::response(['id' => 1], 200),
            'https://api.example.com/b' => Http::response(['id' => 2], 201),
        ]);

        $responses = Http::pool(fn ($pool) => [
            $pool->get('https://api.example.com/a'),
            $pool->get('https://api.example.com/b'),
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame(200, $responses[0]->status());
        $this->assertSame(['id' => 1], $responses[0]->json());
        $this->assertSame(201, $responses[1]->status());
        $this->assertSame(['id' => 2], $responses[1]->json());
    }

    /**
     * @return void
     */
    public function test_pool_empty_callback_returns_empty_array(): void
    {
        Http::fake();

        $responses = Http::pool(fn ($pool) => []);

        $this->assertSame([], $responses);
    }

    /**
     * @return void
     */
    public function test_pool_mixed_methods(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $responses = Http::pool(fn ($pool) => [
            $pool->get('https://api.example.com/a'),
            $pool->post('https://api.example.com/b'),
            $pool->delete('https://api.example.com/c'),
        ]);

        $this->assertCount(3, $responses);

        foreach ($responses as $r) {
            $this->assertSame(200, $r->status());
        }
    }

    /**
     * @return void
     */
    public function test_pool_with_request_builder_methods(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $responses = Http::pool(fn ($pool) => [
            $pool->post('https://api.example.com/users')->withJson(['name' => 'Alice']),
        ]);

        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]->status());
    }

    // ─── Http::async() ────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_async_returns_pool(): void
    {
        Http::fake();

        $pool = Http::async();

        $this->assertInstanceOf(Pool::class, $pool);
    }

    /**
     * @return void
     */
    public function test_async_pool_wait_executes_tracked_requests(): void
    {
        Http::fake([
            'https://api.example.com/x' => Http::response(['x' => 1], 200),
            'https://api.example.com/y' => Http::response(['y' => 2], 201),
        ]);

        $pool = Http::async();
        $req1 = $pool->get('https://api.example.com/x');
        $req2 = $pool->get('https://api.example.com/y');

        $responses = $pool->wait();

        $this->assertCount(2, $responses);
        $this->assertSame(200, $responses[0]->status());
        $this->assertSame(201, $responses[1]->status());

        // PooledRequest resolves after wait()
        $this->assertSame(200, $req1->response()->status());
        $this->assertSame(201, $req2->response()->status());
    }

    /**
     * @return void
     */
    public function test_async_pool_tracks_request_order(): void
    {
        Http::fake([
            'https://api.example.com/1' => Http::response(['n' => 1], 200),
            'https://api.example.com/2' => Http::response(['n' => 2], 200),
            'https://api.example.com/3' => Http::response(['n' => 3], 200),
        ]);

        $pool = Http::async();
        $pool->get('https://api.example.com/1');
        $pool->get('https://api.example.com/2');
        $pool->get('https://api.example.com/3');

        $responses = $pool->wait();

        $this->assertSame(['n' => 1], $responses[0]->json());
        $this->assertSame(['n' => 2], $responses[1]->json());
        $this->assertSame(['n' => 3], $responses[2]->json());
    }

    // ─── Pool::execute() resolves PooledRequests ──────────────────────────────

    /**
     * @return void
     */
    public function test_pool_execute_resolves_pooled_requests(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(200, 'body')]);
        $pool = new Pool($transport);

        $req = new PooledRequest('GET', 'https://api.example.com');
        $pool->execute([$req]);

        $this->assertSame(200, $req->response()->status());
    }

    /**
     * @return void
     */
    public function test_pool_wait_resolves_tracked_requests(): void
    {
        $transport = new FakeTransport(['*' => new HttpResponse(204, '')]);
        $pool = new Pool($transport);

        $req = $pool->get('https://api.example.com');
        $pool->wait();

        $this->assertSame(204, $req->response()->status());
    }

    // ─── Pool uses sequential transport for non-CurlTransport ────────────────

    /**
     * @return void
     */
    public function test_pool_sequential_path_calls_transport_for_each_request(): void
    {
        $callCount = 0;
        $transport = new class ($callCount) implements TransportInterface {
            public function __construct(private int &$ref)
            {
            }

            /** @param array<string, string> $headers */
            public function send(string $method, string $url, array $headers, string $body): HttpResponse
            {
                $this->ref++;
                return new HttpResponse(200, "call {$this->ref}");
            }
        };

        $pool = new Pool($transport);
        $pool->execute([
            new PooledRequest('GET', 'https://a.example.com'),
            new PooledRequest('GET', 'https://b.example.com'),
        ]);

        $this->assertSame(2, $callCount);
    }
}
