<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

it('makes the client refuse what a record of your own says was sent', function (Closure $remember, Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);

    expect($remember($month))->toBeTrue();

    expect(fn () => $send(app(DatadisClient::class), $month))->toThrow(RepetitionWindowException::class);
    expect(guardedRequests())->toBe(0);
})->with([
    'hourly consumption' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'quarter-hourly consumption' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, measurementType: MeasurementType::QuarterHourly),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly),
    ],
    'consumption of a range' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m->addMonths(-1), $m),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(-1), $m),
    ],
    'consumption for a holder' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, authorizedNif: Nif::fromString('12345678Z')),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'consumption of the account itself, given with its own NIF' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, authorizedNif: Nif::fromString('00000000T')),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'maximum power' => [
        fn ($m) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m),
    ],
    'maximum power, whoever the holder is (Datadis does not key it on the holder)' => [
        fn ($m) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getMaxPowerOf(supplyOf($c), $m),
    ],
    'reactive energy' => [
        fn ($m) => Datadis::rememberReactive(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getReactiveDataOf(supplyOf($c), $m),
    ],
]);

it('does not block a query that differs from the one remembered', function (Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);
    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $month);

    $send(app(DatadisClient::class), $month);

    expect(guardedRequests())->toBe(1);
})->with([
    'another month' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(1))],
    'quarter-hourly instead of hourly' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly)],
    'another holder' => [fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m)],
    'maximum power, which was not remembered' => [fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m)],
]);

it('remembers for what is left of the window, from the time the query was sent', function () {
    $record = fn (int $secondsAgo) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$secondsAgo} seconds"));

    // Just inside the window (24 h and 10 min): still blocks, and the time kept is the original one.
    expect($record(RequestLedger::WINDOW_SECONDS - 60))->toBeTrue();

    // The newest attempt wins: asking again with a later time moves the guard to it ...
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable))->toBeTrue();

    // ... and an older time, or the same one again, changes nothing.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-2 hours')))->toBeFalse();

    // Out of the window: nothing to protect, so nothing is recorded.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(3), at: new DateTimeImmutable('-'.(RequestLedger::WINDOW_SECONDS + 1).' seconds')))->toBeFalse();
    fakeEverything();
    app(DatadisClient::class)->getMaxPowerOf(supplyOf(app(DatadisClient::class)), monthsAgo(3));
    expect(guardedRequests())->toBe(1);
});

it('keeps the original time of the query, not the moment it was remembered', function () {
    $sentAt = new DateTimeImmutable('-20 hours');
    Datadis::rememberReactive(Cups::fromString(CUPS), '2', monthsAgo(2), at: $sentAt);

    $ledger = (fn () => $this->ledger())->call(app(Manager::class));
    $last = $ledger->lastAttempt('00000000T', ['cups' => CUPS, 'distributorCode' => '2', 'startDate' => monthsAgo(2)->format(), 'endDate' => monthsAgo(2)->format(), 'authorizedNif' => null]);

    expect($last?->getTimestamp())->toBe($sentAt->getTimestamp());
});

it('does not take a newer attempt back to an older time', function () {
    fakeEverything();
    $client = app(DatadisClient::class);
    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));   // sent now

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-5 hours')))->toBeFalse();
});

it('keys the entries on the account it is given', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'x']);
    fakeEverything();
    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'other');

    // The default account is not blocked by what was remembered for another one.
    $client = app(DatadisClient::class);
    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));
    expect(guardedRequests())->toBe(1);

    // The other account is.
    $other = app(Manager::class)->account('other');
    expect(fn () => $other->getMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2)))->toThrow(RepetitionWindowException::class);
});

it('refuses what cannot be remembered', function (Closure $call, string $message) {
    expect($call)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a point type out of range' => [fn () => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 9, monthsAgo(2)), 'point type'],
    'a bad distributor code' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), 'not a code!', monthsAgo(2)), 'distributor code'],
    'a time in the future' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('+2 hours')), 'future'],
    'an account that is not configured' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'), 'not configured'],
]);

/** An array store that notes the lifetime each key was stored with (Laravel's add() on it is a get and a put). */
class TtlSpyStore extends ArrayStore
{
    /** @var array<string, int> */
    public static array $ttls = [];

    public function put($key, $value, $seconds)
    {
        self::$ttls[$key] = (int) $seconds;

        return parent::put($key, $value, $seconds);
    }
}

it('keeps an old attempt only for what is left of its window, not for a whole new one', function (int $hoursAgo, int $expected) {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$hoursAgo} hours"));

    $ledgerKeys = array_filter(TtlSpyStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH);

    expect($ledgerKeys)->toHaveCount(1);
    // What is left of 24 h 10 min, give or take the seconds the test takes.
    expect(array_values($ledgerKeys)[0])->toBeGreaterThanOrEqual($expected - 2)->toBeLessThanOrEqual($expected + 2);
})->with([
    'sent just now: the whole window' => [0, RequestLedger::WINDOW_SECONDS],
    'sent 12 hours ago: half of it' => [12, RequestLedger::WINDOW_SECONDS - 12 * 3600],
    'sent 23 hours ago: an hour and ten minutes' => [23, RequestLedger::WINDOW_SECONDS - 23 * 3600],
]);

it('keeps the newest attempt of a history, whatever the order it is imported in', function (array $hoursAgo) {
    $newest = min($hoursAgo);
    $month = fn () => monthsAgo(2);

    foreach ($hoursAgo as $hours) {
        Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable("-{$hours} hours"));
    }

    // The guard holds the time of the newest attempt: it is the one whose window ends last.
    expect(abs(heldTime($month) - (time() - $newest * 3600)))->toBeLessThanOrEqual(5);
})->with([
    'the oldest first' => [[23, 1]],
    'the newest first' => [[1, 23]],
    'the same twice' => [[5, 5]],
    'three, mixed' => [[10, 2, 20]],
    'ten, descending' => [[23, 21, 19, 17, 15, 13, 11, 9, 7, 5]],
]);

it('lets the newest attempt of a history set how long the guard waits', function (array $hoursAgo) {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    foreach ($hoursAgo as $hours) {
        Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$hours} hours"));
    }

    $ledgerKeys = array_filter(TtlSpyStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH);
    $expected = RequestLedger::WINDOW_SECONDS - min($hoursAgo) * 3600;

    // The entry now lives until the newest attempt's window ends, not the oldest one's.
    expect($ledgerKeys)->toHaveCount(1);
    expect(array_values($ledgerKeys)[0])->toBeGreaterThanOrEqual($expected - 2)->toBeLessThanOrEqual($expected + 2);
})->with([
    'the oldest first' => [[23, 1]],
    'the newest first' => [[1, 23]],
]);

it('replaces a held entry whose time has already expired', function () {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-23 hours'));
    $key = array_key_first(array_filter(TtlSpyStore::$ttls, fn ($ttl, $k) => str_starts_with($k, 'datadis_query_'), ARRAY_FILTER_USE_BOTH));

    // The held entry keeps a time older than the window (as it would once the first attempt aged out).
    app('cache')->store('spy')->put($key, time() - RequestLedger::WINDOW_SECONDS - 3600, 3600);
    expect(heldTime(fn () => monthsAgo(2)))->toBeNull();

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
    expect(abs(heldTime(fn () => monthsAgo(2)) - (time() - 3600)))->toBeLessThanOrEqual(5);
});

/** An array store that misses the first read of a guard key, as if a worker had sent between two steps of an import. */
class BlindOnceStore extends ArrayStore
{
    public static bool $blind = false;

    public function get($key)
    {
        if (self::$blind && str_starts_with((string) $key, 'datadis_query_')) {
            self::$blind = false;

            return null;
        }

        return parent::get($key);
    }
}

it('does not take back a newer attempt that appeared while the history was being imported', function () {
    Cache::extend('blind', fn () => Cache::repository(new BlindOnceStore));
    config()->set('cache.stores.blind', ['driver' => 'blind']);
    config()->set('datadis-client.cache.store', 'blind');

    // A newer attempt is already held (a worker sent it) ...
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();

    // ... but the import does not see it on its first read. It finds the key held when it tries to take it, and must
    // read again: the held attempt is newer than the one it brings, so it leaves it alone.
    BlindOnceStore::$blind = true;
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-23 hours')))->toBeFalse();

    expect(abs(heldTime(fn () => monthsAgo(2)) - (time() - 3600)))->toBeLessThanOrEqual(5);
});

it('takes a lock per query while it imports, so two imports cannot leave the older time', function () {
    $month = fn () => monthsAgo(2);

    // Nobody else imports this query: it goes through, and the lock is free again afterwards.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
    $lock = Cache::lock(importLockName($month), 5);
    expect($lock->get())->toBeTrue();

    // Another import of the same query holds it: this one waits, and gives up with a clear error.
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable('-2 hours')))
        ->toThrow(LedgerUnavailableException::class, 'import the history from one process');
    $lock->release();

    // Another query is not blocked by that lock.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(3), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
});

/** A store that cannot lock: only the Store contract, kept in an array. */
class NoLockStore implements Store
{
    /** @var array<string, array{mixed, int}> */
    private array $items = [];

    public function get($key)
    {
        return isset($this->items[$key]) && $this->items[$key][1] > time() ? $this->items[$key][0] : null;
    }

    public function many(array $keys)
    {
        return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = [$value, time() + $seconds];

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key][0] = ($this->get($key) ?? 0) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 315360000);
    }

    public function touch($key, $seconds)
    {
        if (! isset($this->items[$key])) {
            return false;
        }

        $this->items[$key][1] = time() + $seconds;

        return true;
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}

it('imports on a store that cannot lock, which then needs one process', function () {
    Cache::extend('nolock', fn () => Cache::repository(new NoLockStore));
    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
    config()->set('datadis-client.cache.store', 'nolock');

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-3 hours')))->toBeTrue();
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();   // the newer replaces it
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-2 hours')))->toBeFalse();
});

it('refuses to remember on a cache repository it cannot give a lifetime to', function () {
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('store')->andReturn(Mockery::mock(Repository::class));
    app()->instance(Factory::class, $factory);

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2)))
        ->toThrow(ConfigurationException::class, 'Laravel cache repository');
});

it('lives longer than the window for a time a little ahead, so it never expires before its own window', function () {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('+500 seconds'));

    $ttl = array_values(array_filter(TtlSpyStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH))[0];
    expect($ttl)->toBeGreaterThanOrEqual(RequestLedger::WINDOW_SECONDS + 498)->toBeLessThanOrEqual(RequestLedger::WINDOW_SECONDS + 502);
});

it('takes the edges of the window and of the clock tolerance as the guard does', function (int $secondsAgo, bool|string $expected) {
    $call = fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$secondsAgo} seconds"));

    if ($expected === 'throws') {
        expect($call)->toThrow(InvalidArgumentException::class, 'future');

        return;
    }

    expect($call())->toBe($expected);
})->with([
    'one second inside the window' => [RequestLedger::WINDOW_SECONDS - 1, true],
    'exactly the window' => [RequestLedger::WINDOW_SECONDS, false],
    'ten minutes ahead, the tolerance' => [-RequestLedger::CLOCK_TOLERANCE_SECONDS + 1, true],
    'a little more than the tolerance ahead' => [-RequestLedger::CLOCK_TOLERANCE_SECONDS - 5, 'throws'],
]);

it('does not record the same attempt twice', function () {
    $at = new DateTimeImmutable('-2 hours');

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: $at))->toBeTrue();
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: $at))->toBeFalse();   // the same time: nothing newer to keep
});

it('is one guard entry for maximum power and reactive energy, as for the client', function (Closure $remember, Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);

    expect($remember($month))->toBeTrue();

    expect(fn () => $send(app(DatadisClient::class), $month))->toThrow(RepetitionWindowException::class);
    expect(guardedRequests())->toBe(0);
})->with([
    'reactive remembered, maximum power refused' => [
        fn ($m) => Datadis::rememberReactive(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m),
    ],
    'maximum power remembered, reactive refused' => [
        fn ($m) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getReactiveDataOf(supplyOf($c), $m),
    ],
]);

it('gives a consumption entry the lifetime left of its window too', function () {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, monthsAgo(2), at: new DateTimeImmutable('-20 hours'));

    $ttl = array_values(array_filter(TtlSpyStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH))[0];
    expect($ttl)->toBeGreaterThanOrEqual(RequestLedger::WINDOW_SECONDS - 20 * 3600 - 2)->toBeLessThanOrEqual(RequestLedger::WINDOW_SECONDS - 20 * 3600 + 2);
});

/** A file store that notes the lifetime each key is added or put with: the real add() path of a shared store. */
class SpyFileStore extends FileStore
{
    /** @var array<string, int> */
    public static array $ttls = [];

    public function add($key, $value, $seconds)
    {
        self::$ttls[$key] = (int) $seconds;

        return parent::add($key, $value, $seconds);
    }

    public function put($key, $value, $seconds)
    {
        self::$ttls[$key] = (int) $seconds;

        return parent::put($key, $value, $seconds);
    }
}

it('works on a file store, through its own add(), for both orders of a history', function (array $hoursAgo) {
    SpyFileStore::$ttls = [];
    $directory = sys_get_temp_dir().'/datadis-file-store-'.bin2hex(random_bytes(4));
    Cache::extend('spyfile', fn () => Cache::repository(new SpyFileStore(new Filesystem, $directory)));
    config()->set('cache.stores.spyfile', ['driver' => 'spyfile']);
    config()->set('datadis-client.cache.store', 'spyfile');

    foreach ($hoursAgo as $hours) {
        Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$hours} hours"));
    }

    $ttl = array_values(array_filter(SpyFileStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH))[0];
    $expected = RequestLedger::WINDOW_SECONDS - min($hoursAgo) * 3600;

    expect($ttl)->toBeGreaterThanOrEqual($expected - 2)->toBeLessThanOrEqual($expected + 2);
    expect(abs(heldTime(fn () => monthsAgo(2)) - (time() - min($hoursAgo) * 3600)))->toBeLessThanOrEqual(2);

    (new Filesystem)->deleteDirectory($directory);
})->with([
    'the oldest first' => [[23, 2]],
    'the newest first' => [[2, 23]],
]);

it('refuses a reversed range when it remembers', function () {
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(1), monthsAgo(3)))
        ->toThrow(InvalidArgumentException::class, 'must not be after');
});

it('leaves a held attempt of the same time alone when it only finds it while taking the key', function () {
    Cache::extend('blind', fn () => Cache::repository(new BlindOnceStore));
    config()->set('cache.stores.blind', ['driver' => 'blind']);
    config()->set('datadis-client.cache.store', 'blind');
    $at = new DateTimeImmutable('-3 hours');

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: $at))->toBeTrue();

    BlindOnceStore::$blind = true;
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: $at))->toBeFalse();
});
