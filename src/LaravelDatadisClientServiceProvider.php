<?php

namespace Lenorix\LaravelDatadisClient;

use Lenorix\DatadisClient\DatadisClient;
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
    }
}
