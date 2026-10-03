<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Internal\GuardLedgers;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;

it('refuses a repeated query across clients through the shared cache', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));

    app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

    // A new client, as another worker would have: only the cache remembers the first query.
    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))
        ->toThrow(RepetitionWindowException::class);
    Http::assertSentCount(3); // login, supplies, one consumption
});

it('shares the login token between clients through the cache', function () {
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();
    app(DatadisClient::class)->getSupplies();

    $logins = Http::recorded(fn (Request $r) => str_contains($r->url(), 'login'));
    expect($logins)->toHaveCount(1);
});

it('keeps the token in the default cache store of Laravel', function () {
    config()->set('cache.stores.other', ['driver' => 'array']);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);
    // Another default store never saw the token: a client on it logs in again.
    config()->set('cache.default', 'other');
    app(DatadisClient::class)->getSupplies();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(2);
});

it('derives the guard secret from the app key, or takes its own', function () {
    config()->set('app.key', 'base64:'.base64_encode('short'));
    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class);

    config()->set('datadis-client.ledger.key', str_repeat('z', 32));
    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('adds to the cache only when the key is absent', function () {
    $store = new LaravelAtomicStore(app('cache')->store());

    expect($store->add('k', 1, 60))->toBeTrue();
    expect($store->add('k', 2, 60))->toBeFalse();
});

it('counts a consumption query lost in transit as used, so it is not repeated', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-consumption-data*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        },
    ]);

    try {
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
    } catch (TransportException $e) {
    }

    expect($e)->toBeInstanceOf(TransportException::class);
    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))->toThrow(RepetitionWindowException::class);
    expect($attempts)->toBe(1);
});

it('logs in again when the token it was given has already expired', function () {
    $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $expired = $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'a', 'iat' => time() - 200000, 'exp' => time() - 100000])).'.sig';
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::sequence()->push($expired, 200, ['Content-Type' => 'text/plain'])->push(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []]),
    ]);

    app(DatadisClient::class)->getSupplies();
    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(2);
});

it('refuses a guarded query without sending it when the cache store cannot be used', function () {
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache is down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache is down');
        }

        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache is down');
        }
    }));
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('cache.default', 'broken');

    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))->toThrow(LedgerUnavailableException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(0);
});

it('never lets the token or its key reach Laravel\'s cache events', function () {
    $token = fakeToken();
    $events = [];
    Event::listen('Illuminate\Cache\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = [$name, json_encode(array_map(fn ($event) => get_object_vars($event), $payload), JSON_PARTIAL_OUTPUT_ON_ERROR)];
    });
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response($token, 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []]),
    ]);

    app(DatadisClient::class)->getSupplies();   // writes the token
    app(DatadisClient::class)->getSupplies();   // reads it back

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);   // the token was cached and reused
    expect($events)->toBe([]);
});

it('takes a cache repository that is not Laravel\'s as it is', function () {
    $factory = Mockery::mock(Illuminate\Contracts\Cache\Factory::class);
    $factory->shouldReceive('store')->andReturn(Mockery::mock(Repository::class));
    app()->instance(Illuminate\Contracts\Cache\Factory::class, $factory);

    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('refuses a guard key that is not a text, instead of falling back to the application key', function (mixed $key) {
    config()->set('datadis-client.ledger.key', $key);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'must be a text or null');
})->with(['int' => [123], 'array' => [['secret']], 'bool' => [true]]);

it('keys the guard with a secret derived from the application key, not the key itself', function () {
    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', str_repeat('a', 32));
    $secret = (new GuardLedgers(app()))->secret();

    expect(strlen($secret))->toBe(32);
    expect($secret)->not->toBe(str_repeat('a', 32));
    expect($secret)->toBe(hash_hmac('sha256', 'laravel-datadis-client: 24 hour guard', str_repeat('a', 32), true));

    config()->set('datadis-client.ledger.key', 'my-own-guard-secret');
    expect((new GuardLedgers(app()))->secret())->toBe('my-own-guard-secret');
});

it('refuses an application key too short to key the guard', function () {
    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', 'short');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'too short');
});

it('refuses a default cache store that remembers nothing, instead of refusing every query as already sent', function () {
    config()->set('cache.stores.nothing', ['driver' => 'null']);
    config()->set('cache.default', 'nothing');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'remembers nothing');
});
