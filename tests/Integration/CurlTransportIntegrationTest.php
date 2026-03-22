<?php

declare(strict_types=1);

namespace Tests\Integration;

use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\HttpClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Integration tests for CurlTransport — require real outbound network access.
 *
 * Run selectively:
 *   vendor/bin/phpunit --testsuite Integration
 *   vendor/bin/phpunit --group integration
 *
 * These tests make HTTP requests to httpbin.org. They are excluded from the
 * default "Tests" suite and must not run in standard CI without an internet
 * connection. Use a dedicated integration-test job or run locally inside Docker
 * with outbound access enabled.
 *
 * @package Tests\Integration
 */
#[CoversClass(CurlTransport::class)]
#[Group('integration')]
final class CurlTransportIntegrationTest extends TestCase
{
    private CurlTransport $transport;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new CurlTransport();
    }

    /**
     * @return void
     */
    public function testGetReturns200(): void
    {
        $response = $this->transport->send('GET', 'https://httpbin.org/get', [], '');

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->ok());
    }

    /**
     * @return void
     */
    public function testGetBodyIsValidJson(): void
    {
        $response = $this->transport->send('GET', 'https://httpbin.org/get', [], '');

        $data = json_decode($response->body(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('url', $data);
        assert(is_string($data['url']));
        $this->assertStringContainsString('httpbin.org/get', $data['url']);
    }

    /**
     * @return void
     */
    public function testRequestHeadersAreSentToServer(): void
    {
        $response = $this->transport->send(
            'GET',
            'https://httpbin.org/get',
            ['X-Integration-Test' => 'ez-php'],
            ''
        );

        $data = json_decode($response->body(), true);

        $this->assertIsArray($data);
        $this->assertIsArray($data['headers']);
        $this->assertSame('ez-php', $data['headers']['X-Integration-Test']);
    }

    /**
     * @return void
     */
    public function testPostSendsJsonBody(): void
    {
        $body = (string) json_encode(['foo' => 'bar']);

        $response = $this->transport->send(
            'POST',
            'https://httpbin.org/post',
            ['Content-Type' => 'application/json'],
            $body
        );

        $this->assertSame(200, $response->status());

        $data = json_decode($response->body(), true);

        $this->assertIsArray($data);
        $this->assertSame(['foo' => 'bar'], $data['json']);
    }

    /**
     * @return void
     */
    public function testResponseHeadersAreParsedAndLowercased(): void
    {
        $response = $this->transport->send('GET', 'https://httpbin.org/get', [], '');

        $contentType = $response->header('content-type', '');

        $this->assertStringContainsString('application/json', $contentType);
    }

    /**
     * @return void
     */
    public function testRedirectIsFollowedTransparently(): void
    {
        // httpbin.org/redirect/1 issues one 302 redirect to /get
        $response = $this->transport->send('GET', 'https://httpbin.org/redirect/1', [], '');

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test4xxResponseIsReturnedNotThrown(): void
    {
        $response = $this->transport->send('GET', 'https://httpbin.org/status/404', [], '');

        $this->assertSame(404, $response->status());
        $this->assertFalse($response->ok());
    }

    /**
     * @return void
     */
    public function test5xxResponseIsReturnedNotThrown(): void
    {
        $response = $this->transport->send('GET', 'https://httpbin.org/status/500', [], '');

        $this->assertSame(500, $response->status());
        $this->assertFalse($response->ok());
    }

    /**
     * @return void
     */
    public function testEmptyUrlThrowsHttpClientException(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('URL cannot be empty.');

        $this->transport->send('GET', '', [], '');
    }
}
