<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\Application\Application;
use EzPhp\Exceptions\ApplicationException;
use EzPhp\Exceptions\ContainerException;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionException;
use Tests\TestCase;

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
final class HttpClientServiceProviderTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_http_client_is_bound_in_container(): void
    {
        $app = new Application();
        $app->register(HttpClientServiceProvider::class);
        $app->bootstrap();

        $client = $app->make(HttpClient::class);

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_transport_interface_resolves_to_curl_transport(): void
    {
        $app = new Application();
        $app->register(HttpClientServiceProvider::class);
        $app->bootstrap();

        $transport = $app->make(TransportInterface::class);

        $this->assertInstanceOf(CurlTransport::class, $transport);
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_static_facade_is_wired_after_bootstrap(): void
    {
        $app = new Application();
        $app->register(HttpClientServiceProvider::class);
        $app->bootstrap();

        $containerClient = $app->make(HttpClient::class);

        $this->assertSame($containerClient, Http::getClient());
    }

    /**
     */
    public function test_facade_get_returns_http_request(): void
    {
        $app = new Application();
        $app->register(HttpClientServiceProvider::class);
        $app->bootstrap();

        $request = Http::get('https://example.com');

        $this->assertInstanceOf(HttpRequest::class, $request);
    }
}
