<?php

namespace Lenorix\LaravelDatadisClient;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\PublicApiClient;
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
            ->hasCommand(SuppliesCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(LaravelDatadisClient::class);

        // bind, not singleton: the client is built where it is used, after any Http::fake() in a test.
        $this->app->bind(DatadisClient::class, fn ($app) => $app->make(LaravelDatadisClient::class)->account());
        $this->app->bind(PublicApiClient::class, fn ($app) => $app->make(LaravelDatadisClient::class)->publicApi());

        // A repeated query is refused before anything is sent: expected under a scheduler, not an error.
        // This runs after the application's own `withExceptions()`, so `report_level` => null leaves its setting alone.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $level = config('datadis-client.report_level');

            if (is_string($level) && $level !== '' && method_exists($handler, 'level')) {
                $handler->level(RepetitionWindowException::class, $level);
            }
        });
    }
}
