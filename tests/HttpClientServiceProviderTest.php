<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\Application\Application;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\ApplicationTestCase;

/**
 * Class HttpClientServiceProviderTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpClientServiceProvider::class)]
#[UsesClass(Http::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpRequest::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(CurlTransport::class)]
final class HttpClientServiceProviderTest extends ApplicationTestCase
{
    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(HttpClientServiceProvider::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_http_client_is_bound_in_container(): void
    {
        $this->assertInstanceOf(HttpClient::class, $this->app()->make(HttpClient::class));
    }

    /**
     * @return void
     */
    public function test_transport_interface_resolves_to_curl_transport(): void
    {
        $this->assertInstanceOf(CurlTransport::class, $this->app()->make(TransportInterface::class));
    }

    /**
     * @return void
     */
    public function test_static_facade_is_wired_after_bootstrap(): void
    {
        $this->assertSame($this->app()->make(HttpClient::class), Http::getClient());
    }

    /**
     * @return void
     */
    public function test_facade_get_returns_http_request(): void
    {
        $this->assertInstanceOf(HttpRequest::class, Http::get('https://example.com'));
    }
}
