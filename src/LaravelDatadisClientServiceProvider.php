<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\LaravelDatadisClient\Commands\AuthorizationsCommand;
use Lenorix\LaravelDatadisClient\Commands\AuthorizeCommand;
use Lenorix\LaravelDatadisClient\Commands\CancelAuthorizationCommand;
use Lenorix\LaravelDatadisClient\Commands\ConsumptionCommand;
use Lenorix\LaravelDatadisClient\Commands\ContractCommand;
use Lenorix\LaravelDatadisClient\Commands\SuppliesCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDatadisClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-datadis-client')
            ->hasConfigFile()
            ->hasCommands([
                SuppliesCommand::class,
                ContractCommand::class,
                ConsumptionCommand::class,
                AuthorizationsCommand::class,
                AuthorizeCommand::class,
                CancelAuthorizationCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // bind, not singleton: the client is built where it is used, after any Http::fake() in a test.
        $this->app->bind(DatadisClient::class, fn (Application $app) => $app->make(LaravelDatadisClient::class)->account());
        $this->app->bind(PublicApiClient::class, fn (Application $app) => $app->make(LaravelDatadisClient::class)->publicApi());

        // A repeated query is refused before anything is sent: expected under a scheduler, not an error.
        // This runs after the application's own `withExceptions()`, so `report_level` => null leaves its setting alone.
        // It never throws: a bad level is reported by account() and publicApi(), and ignored here.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            try {
                $level = $this->app->make(LaravelDatadisClient::class)->reportLevel();
            } catch (ConfigurationException) {
                return;
            }

            if ($level !== null && method_exists($handler, 'level')) {
                $handler->level(RepetitionWindowException::class, $level);
            }
        });
    }
}
