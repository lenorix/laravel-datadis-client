<?php

namespace Lenorix\LaravelDatadisClient;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\LaravelDatadisClient\Commands\SuppliesCommand;
use Psr\Log\LogLevel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDatadisClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-datadis-client')
            ->hasConfigFile()
            ->hasCommand(SuppliesCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(LaravelDatadisClient::class);

        // bind, not singleton: the client is built where it is used, after any Http::fake() in a test.
        $this->app->bind(DatadisClient::class, fn ($app) => $app->make(LaravelDatadisClient::class)->account());
        $this->app->bind(PublicApiClient::class, fn ($app) => $app->make(LaravelDatadisClient::class)->publicApi());

        // A repeated query is refused before anything is sent: expected under a scheduler, not an error.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'level')) {
                $handler->level(RepetitionWindowException::class, LogLevel::WARNING);
            }
        });
    }
}
