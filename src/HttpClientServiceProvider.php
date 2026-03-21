<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class HttpClientServiceProvider
 *
 * Binds the HTTP client and its transport into the container and wires the
 * Http static facade.
 *
 * Register in provider/modules.php:
 *
 *   return [
 *       HttpClientServiceProvider::class,
 *   ];
 *
 * To use a custom transport (e.g. a test double) rebind TransportInterface
 * before this provider boots:
 *
 *   $app->bind(TransportInterface::class, MyCustomTransport::class);
 *
 * @package EzPhp\HttpClient
 */
final class HttpClientServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(TransportInterface::class, CurlTransport::class);

        $this->app->bind(HttpClient::class, function (ContainerInterface $app): HttpClient {
            /** @var TransportInterface $transport */
            $transport = $app->make(TransportInterface::class);
            $client = new HttpClient($transport);
            Http::setClient($client);

            return $client;
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        // Eagerly resolve so the static facade is wired before any code
        // calls Http::get() etc.
        $this->app->make(HttpClient::class);
    }
}
