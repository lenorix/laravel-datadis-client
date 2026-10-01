<?php

namespace Lenorix\LaravelDatadisClient\Tests;

use Lenorix\LaravelDatadisClient\LaravelDatadisClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelDatadisClientServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('cache.default', 'array');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('datadis-client.accounts.default.username', '00000000T');
        config()->set('datadis-client.accounts.default.password', 'secret');
    }
}
