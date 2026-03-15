<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
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
     * Build a minimal container stub and boot the provider against it.
     */
    private function makeBootedContainer(): ContainerInterface
    {
        $container = new class () implements ContainerInterface {
            /** @var array<string, callable> */
            private array $bindings = [];

            /** @var array<string, object> */
            private array $instances = [];

            public function bind(string $abstract, string|callable|null $factory = null): void
            {
                if (is_callable($factory)) {
                    $this->bindings[$abstract] = $factory;

                    return;
                }

                if (is_string($factory)) {
                    $concrete = $factory;
                    $this->bindings[$abstract] = static function () use ($concrete): object {
                        return new $concrete();
                    };
                }
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->instances[$abstract] = $instance;
            }

            /**
             * @template T of object
             * @param class-string<T> $abstract
             * @return T
             */
            public function make(string $abstract): mixed
            {
                if (isset($this->instances[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract];
                }

                if (isset($this->bindings[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract] = ($this->bindings[$abstract])($this);
                }

                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $provider = new HttpClientServiceProvider($container);
        $provider->register();
        $provider->boot();

        return $container;
    }

    /**
     * @return void
     */
    public function test_http_client_is_bound_in_container(): void
    {
        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(HttpClient::class, $container->make(HttpClient::class));
    }

    /**
     * @return void
     */
    public function test_transport_interface_resolves_to_curl_transport(): void
    {
        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(CurlTransport::class, $container->make(TransportInterface::class));
    }

    /**
     * @return void
     */
    public function test_static_facade_is_wired_after_bootstrap(): void
    {
        $container = $this->makeBootedContainer();

        $this->assertSame($container->make(HttpClient::class), Http::getClient());
    }

    /**
     * @return void
     */
    public function test_facade_get_returns_http_request(): void
    {
        $this->makeBootedContainer();

        $this->assertInstanceOf(HttpRequest::class, Http::get('https://example.com'));
    }
}
