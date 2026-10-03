<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;

/**
 * The guard and the import on the stores a real application shares between workers: the array store (one
 * process), the file store (one server) and the database store, which has its own add() and its own locks.
 */
function useGuardStore(string $driver): void
{
    $config = match ($driver) {
        'array' => ['driver' => 'array'],
        'file' => ['driver' => 'file', 'path' => sys_get_temp_dir().'/datadis-guard-'.bin2hex(random_bytes(4))],
        'database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'cache', 'lock_connection' => 'testing', 'lock_table' => 'cache_locks'],
    };

    if ($driver === 'database') {
        Schema::connection('testing')->create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::connection('testing')->create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    config()->set('cache.stores.guard', $config);
    config()->set('cache.default', 'guard');
    Cache::forgetDriver('guard');
}

it('keeps the newest attempt of a history in whatever order it comes, on every store', function (string $driver, array $hoursAgo) {
    useGuardStore($driver);
    $month = fn () => monthsAgo(2);

    foreach ($hoursAgo as $hours) {
        Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable("-{$hours} hours"));
    }

    expect(abs(heldTime($month) - (time() - min($hoursAgo) * 3600)))->toBeLessThanOrEqual(2);
})->with(['array', 'file', 'database'])->with([
    'the oldest first' => [[23, 1]],
    'the newest first' => [[1, 23]],
    'mixed' => [[10, 2, 20]],
]);

it('lives for what is left of the window on every store', function (string $driver) {
    useGuardStore($driver);

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-23 hours'));

    // The guard holds it for about an hour and ten minutes more, and then it is free: what the store keeps agrees.
    expect(heldTime(fn () => monthsAgo(2)))->not->toBeNull();
    if ($driver === 'database') {
        $row = DB::connection('testing')->table('cache')->where('key', 'like', '%datadis_query_%')->first();
        expect($row->expiration - time())->toBeGreaterThanOrEqual(RequestLedger::WINDOW_SECONDS - 23 * 3600 - 3)->toBeLessThanOrEqual(RequestLedger::WINDOW_SECONDS - 23 * 3600 + 3);
    }
})->with(['array', 'file', 'database']);

it('makes the client refuse a remembered query and take a free one once, on every store', function (string $driver) {
    useGuardStore($driver);
    fakeEverything();
    $month = monthsAgo(2);

    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $month, at: new DateTimeImmutable('-2 hours'));
    $client = app(DatadisClient::class);

    // The remembered query is refused ...
    expect(fn () => $client->getConsumptionDataOf(supplyOf($client), $month))->toThrow(RepetitionWindowException::class);

    // ... a free one goes through once (the client's own claim) and is then refused.
    $client->getMaxPowerOf(supplyOf($client), $month);
    expect(guardedRequests())->toBe(1);
    expect(fn () => $client->getMaxPowerOf(supplyOf($client), $month))->toThrow(RepetitionWindowException::class);
    expect(guardedRequests())->toBe(1);
})->with(['array', 'file', 'database']);

it('takes a lock per account while it imports, on the stores that can lock', function (string $driver) {
    Sleep::fake(syncWithCarbon: true);   // the wait for the lock is simulated: two seconds without waiting them
    useGuardStore($driver);
    $month = fn () => monthsAgo(2);

    $lock = Cache::store('guard')->lock(importLockName(), 5);
    expect($lock->get())->toBeTrue();

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable('-1 hour')))
        ->toThrow(LedgerUnavailableException::class, 'import the history from one process');

    $lock->release();
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $month(), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
})->with(['array', 'file', 'database']);

it('is a ledger store on every cache store: get, set, delete of a missing key, and add over an expired key', function (string $driver) {
    useGuardStore($driver);
    $store = new LaravelAtomicStore(Cache::store('guard'));

    expect($store->get('datadis_query_a'))->toBeNull();
    expect($store->delete('datadis_query_a'))->toBeTrue();            // a key that is not there counts as removed
    expect($store->add('datadis_query_a', 100, 60))->toBeTrue();
    expect($store->add('datadis_query_a', 200, 60))->toBeFalse();     // held
    expect((int) $store->get('datadis_query_a'))->toBe(100);
    expect($store->set('datadis_query_a', 300, 60))->toBeTrue();      // replaces
    expect((int) $store->get('datadis_query_a'))->toBe(300);
    expect($store->delete('datadis_query_a'))->toBeTrue();
    expect($store->get('datadis_query_a'))->toBeNull();

    // An expired key is absent for add().
    $this->travelTo(now());
    expect($store->add('datadis_query_b', 1, 5))->toBeTrue();
    $this->travelTo(now()->addSeconds(6));
    expect($store->add('datadis_query_b', 2, 5))->toBeTrue();
    expect((int) $store->get('datadis_query_b'))->toBe(2);
    $this->travelBack();
})->with(['array', 'file', 'database']);
