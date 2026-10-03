<?php

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

it('sends nothing when it imports', function () {
    FakesDatadis::fake();
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
    $client = (fn () => $this->clientFor(null))->call(importer());

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

it('names the import lock of an account after a keyed hash of its username, forty hex digits long', function () {
    $importer = importer();

    $name = $importer->lockName('00000000T');

    expect($name)->toMatch('/^datadis_import_[0-9a-f]{40}$/');
    expect($name)->not->toContain('00000000T');
    expect($importer->lockName('00000000T'))->toBe($name);              // the same account, the same lock
    expect($importer->lockName('12345678Z'))->not->toBe($name);         // another account, another lock

    config()->set('datadis-client.ledger.key', 'another secret of enough bytes');
    expect($importer->lockName('00000000T'))->not->toBe($name);         // the secret keys it
});

it('keeps the name of the import lock stable, so two versions of the package that import during a deploy exclude each other', function () {
    config()->set('datadis-client.ledger.key', 'my own secret of enough bytes');

    // A golden value: if the name changes, a worker on the old version and one on the new would not share the lock.
    expect((importer())->lockName('00000000T'))->toBe('datadis_import_2e8e861723a567b3ebdc304365f6dadda520681a');
});

/** An array store whose lock cannot be taken: the cache is down. */
class LockDownStore extends ArrayStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('redis down');
    }
}

/** An array store whose lock is taken, and cannot be released: the cache goes down once the import is done. */
class ReleaseDownStore extends ArrayStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            public function release()
            {
                throw new RuntimeException('redis went down after the import');
            }
        };
    }
}

it('says the cache failed when it cannot take the import lock, not a bare exception of the store', function () {
    Cache::extend('lockdown', fn () => Cache::repository(new LockDownStore));
    config()->set('cache.stores.lockdown', ['driver' => 'lockdown']);
    config()->set('cache.default', 'lockdown');

    try {
        Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour'));
    } catch (LedgerUnavailableException $e) {
        expect($e->getMessage())->toContain('cache failed');
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class);

        return;
    }

    throw new LogicException('Expected a LedgerUnavailableException.');
});

it('does not fail an import that is done because the lock could not be released', function () {
    Cache::extend('releasedown', fn () => Cache::repository(new ReleaseDownStore));
    config()->set('cache.stores.releasedown', ['driver' => 'releasedown']);
    config()->set('cache.default', 'releasedown');

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
});

it('keeps the credentials out of the arguments of a stack trace, which error pages and trackers print', function () {
    $before = ini_set('zend.exception_ignore_args', '0');   // the PHP default: the arguments are in the trace
    $traceOf = function (Closure $fails): string {
        try {
            $fails();
        } catch (Throwable $e) {
            return json_encode(array_map(fn ($frame) => $frame['args'] ?? null, $e->getTrace()), JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        throw new LogicException('Expected a failure.');
    };

    // The settings of an account, with the password, reach a method that throws for a wrong HTTP stack.
    config()->set('datadis-client.accounts.default.password', 'HUNTER2PASS');
    config()->set('datadis-client.http.stack', 'bogus');
    expect($traceOf(fn () => app(LaravelDatadisClient::class)->account()))->not->toContain('HUNTER2PASS');

    // The username (a NIF) reaches the lock name, which throws when there is no secret to key it with.
    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', '');
    expect($traceOf(fn () => importer()->lockName('12345678Z')))->not->toContain('12345678Z');

    ini_set('zend.exception_ignore_args', $before);
});
