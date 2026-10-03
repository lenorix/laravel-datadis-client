<?php

use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Internal\AccountSettings;
use Lenorix\LaravelDatadisClient\Internal\GuardLedgers;
use Lenorix\LaravelDatadisClient\Internal\Importer;
use Lenorix\LaravelDatadisClient\Internal\ReportLevel;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

// A literal, not FakesDatadis::CUPS: a constant here would load that class while Pest boots, before a mutation run can replace it
// (every mutant of the helper would then survive). A test checks that the two are the same.
const CUPS = 'ES0000000000000000AA0A';

/** A NIF with a valid control letter for any number. */
function nifOf(int $number): string
{
    return sprintf('%08d', $number).'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
}

function iterations(): int
{
    return (int) (getenv('DATADIS_PBT_ITERATIONS') ?: 200);
}

function logins(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'login'))->map(fn ($pair) => $pair[0]->data())->all();
}

/** A month the client still serves, so the suite does not expire as the calendar moves. */
function monthsAgo(int $months = 3): Month
{
    return Month::current(new DateTimeImmutable)->addMonths(-$months);
}

/** A clean HTTP factory and cache with Datadis faked, for one property case. */
function freshHttp(): void
{
    FakesDatadis::fake();
    app('cache')->store()->clear();
}

/** How many guarded requests reached Datadis. */
function guardedRequests(): int
{
    return Http::recorded(fn (Request $r) => preg_match('/get-(consumption-data|max-power|reactive-data)/', $r->url()) === 1)->count();
}

/** The supply the client finds, so that the Of() calls can be made. */
function supplyOf(DatadisClient $client)
{
    return $client->findSupply(Cups::fromString(CUPS));
}

/** The time the guard holds for a maximum power query of the default account, or null. */
function heldTime(Closure $month): ?int
{
    $ledger = (new GuardLedgers(app()))->ledger('default');

    return $ledger->lastAttempt('00000000T', ['cups' => CUPS, 'distributorCode' => '2', 'startDate' => $month()->format(), 'endDate' => $month()->format(), 'authorizedNif' => null])?->getTimestamp();
}

/** The key of the lock an import takes for the default account: a keyed hash of its username, never the NIF. */
function importLockName(): string
{
    return importer()->lockName('00000000T');
}

/** An importer wired as the manager wires its own: the classes keep nothing, so a second set makes no difference. */
function importer(): Importer
{
    return new Importer(new AccountSettings(app()), new GuardLedgers(app()), new ReportLevel(app()));
}

/** Makes a cache store of the test the default one, under a name, as a deployment would configure its own. */
function useCacheStore(string $name, Store $store): void
{
    Cache::extend($name, fn () => Cache::repository($store));
    config()->set("cache.stores.{$name}", ['driver' => $name]);
    config()->set('cache.default', $name);
}

/**
 * A path in the system's temporary directory, removed (a directory with what it holds, or a file) when the run ends, whatever
 * the test did: what a failing test leaves behind piles up otherwise.
 */
function temporaryPath(string $prefix, string $suffix = ''): string
{
    static $made = [];
    static $registered = false;

    $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6)).$suffix;
    $made[] = $path;

    if (! $registered) {
        $registered = true;
        register_shutdown_function(function () use (&$made) {
            foreach ($made as $path) {
                is_dir($path) ? (new Filesystem)->deleteDirectory($path) : @unlink($path);
            }
        });
    }

    return $path;
}
