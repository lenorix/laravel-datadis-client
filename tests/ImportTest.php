<?php

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

it('sends nothing when it imports', function () {
    fakeEverything();
    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, monthsAgo(2), at: new DateTimeImmutable('-1 hour'));
    Http::assertNothingSent();
});

it('imports with a wrong http setting, which has nothing to do with it', function () {
    config()->set('datadis-client.http.retries', ['max' => 'many']);
    config()->set('datadis-client.http.options.debug', true);

    expect(Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
});

it('still refuses to import for an account that is not configured or whose settings are wrong', function () {
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'))->toThrow(InvalidArgumentException::class, 'not configured');

    config()->set('datadis-client.accounts.broken', ['username' => 'nope', 'password' => 'x']);
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'broken'))->toThrow(ConfigurationException::class);
});

it('names the account that is not configured, the default one included', function () {
    config()->set('datadis-client.default', 'ghost');

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'))->toThrow(InvalidArgumentException::class, '[missing]');
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2)))->toThrow(InvalidArgumentException::class, '[ghost]');
});

it('refuses to import when the report level is wrong, as every other call does', function () {
    config()->set('datadis-client.report_level', 'bogus');

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))
        ->toThrow(ConfigurationException::class, 'report_level');
});

it('imports on a cache repository that is not Laravel\'s, without a lock', function () {
    $held = [];
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('get')->andReturnUsing(fn (string $key) => $held[$key] ?? null);
    $repository->shouldReceive('add')->andReturnUsing(function (string $key, $value) use (&$held) {
        $held[$key] = $value;

        return true;
    });
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('store')->andReturn($repository);
    app()->instance(Factory::class, $factory);

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
    expect($held)->toHaveCount(1);
});

it('gives an import a client that cannot send, whatever the environment says', function () {
    $client = (fn () => $this->clientForImport(null))->call(app(LaravelDatadisClient::class));

    // Not a login, not a data query: the transport is the one that throws ("An import never sends a request"), so nothing can reach Datadis or Http::fake().
    expect(fn () => $client->checkLogin())->toThrow(TransportException::class, 'LogicException');   // the client reports the transport's failure
    Http::assertNothingSent();
});

/** An array store that notes how long an import holds its lock and how long it waits for it. */
class NotingLockStore extends ArrayStore
{
    /** @var list<array{string, int, int|null}> */
    public static array $noted = [];

    public function lock($name, $seconds = 0, $owner = null)
    {
        $store = $this;

        return new class($store, $name, $seconds, $owner) extends ArrayLock
        {
            public function __construct(private readonly NotingLockStore $noting, string $name, private readonly int $held, ?string $owner)
            {
                parent::__construct($noting, $name, $held, $owner);
            }

            public function block($seconds, $callback = null)
            {
                NotingLockStore::$noted[] = [$this->name, $this->held, (int) $seconds];

                return parent::block($seconds, $callback);
            }
        };
    }
}

it('holds the import lock for thirty seconds and waits two for another import, as the README says', function () {
    NotingLockStore::$noted = [];
    Cache::extend('noting', fn () => Cache::repository(new NotingLockStore));
    config()->set('cache.stores.noting', ['driver' => 'noting']);
    config()->set('cache.default', 'noting');

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour'));

    expect(NotingLockStore::$noted)->toHaveCount(1);
    [$name, $held, $waits] = NotingLockStore::$noted[0];
    expect($held)->toBe(30);
    expect($waits)->toBe(2);
    expect($name)->toStartWith('datadis_import_')->not->toContain('00000000T');
});

it('refuses to remember a reactive energy query for an account on API v1, which has none', function () {
    config()->set('datadis-client.accounts.default.api_version', 'v1');

    expect(fn () => Datadis::rememberReactive(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))
        ->toThrow(UnsupportedOperationException::class, 'only in API v2');
    // ... and the maximum power one, which v1 has, is still remembered.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
});
