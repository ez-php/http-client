<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\Application\Application;
use EzPhp\Application\CoreServiceProviders;
use EzPhp\Config\Config;
use EzPhp\Config\ConfigLoader;
use EzPhp\Config\ConfigServiceProvider;
use EzPhp\Console\Command\MakeControllerCommand;
use EzPhp\Console\Command\MakeMiddlewareCommand;
use EzPhp\Console\Command\MakeMigrationCommand;
use EzPhp\Console\Command\MakeProviderCommand;
use EzPhp\Console\Command\MigrateCommand;
use EzPhp\Console\Command\MigrateRollbackCommand;
use EzPhp\Console\Console;
use EzPhp\Console\ConsoleServiceProvider;
use EzPhp\Console\Input;
use EzPhp\Console\Output;
use EzPhp\Container\Container;
use EzPhp\Database\Database;
use EzPhp\Database\DatabaseServiceProvider;
use EzPhp\Exceptions\ApplicationException;
use EzPhp\Exceptions\ContainerException;
use EzPhp\Exceptions\DefaultExceptionHandler;
use EzPhp\Exceptions\ExceptionHandlerServiceProvider;
use EzPhp\HttpClient\CurlTransport;
use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpClientServiceProvider;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use EzPhp\Migration\MigrationServiceProvider;
use EzPhp\Migration\Migrator;
use EzPhp\Routing\Route;
use EzPhp\Routing\Router;
use EzPhp\Routing\RouterServiceProvider;
use EzPhp\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionException;
use Tests\DatabaseTestCase;

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
#[UsesClass(Application::class)]
#[UsesClass(Container::class)]
#[UsesClass(CoreServiceProviders::class)]
#[UsesClass(Config::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConfigServiceProvider::class)]
#[UsesClass(Database::class)]
#[UsesClass(DatabaseServiceProvider::class)]
#[UsesClass(MigrationServiceProvider::class)]
#[UsesClass(Migrator::class)]
#[UsesClass(RouterServiceProvider::class)]
#[UsesClass(Route::class)]
#[UsesClass(Router::class)]

#[UsesClass(DefaultExceptionHandler::class)]
#[UsesClass(ExceptionHandlerServiceProvider::class)]
#[UsesClass(ConsoleServiceProvider::class)]
#[UsesClass(Console::class)]
#[UsesClass(MigrateCommand::class)]
#[UsesClass(MigrateRollbackCommand::class)]
#[UsesClass(MakeMigrationCommand::class)]

#[UsesClass(MakeControllerCommand::class)]
#[UsesClass(MakeMiddlewareCommand::class)]
#[UsesClass(MakeProviderCommand::class)]
#[UsesClass(Input::class)]
#[UsesClass(Output::class)]
#[UsesClass(ServiceProvider::class)]
final class HttpClientServiceProviderTest extends DatabaseTestCase
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
