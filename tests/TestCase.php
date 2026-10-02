<?php

namespace Lenorix\LaravelDatadisClient\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelDatadisClient\LaravelDatadisClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // A request nothing faked would reach the real Datadis, where a repeated query counts for 24 hours.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelDatadisClientServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('cache.default', 'array');
        // Retries without waiting: the tests that fail a call do not sleep.
        config()->set('datadis-client.http.retries', ['max' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 1]);
        // Explicit, although the testing environment already means it: Http::fake() needs Laravel's stack.
        config()->set('datadis-client.http.stack', 'laravel');
        // The guzzle stack must never reach the network from a test: an empty mock fails any call a test did not queue.
        config()->set('datadis-client.http.options.handler', HandlerStack::create(new MockHandler));
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('datadis-client.accounts.default.username', '00000000T');
        config()->set('datadis-client.accounts.default.password', 'secret');
    }
}
