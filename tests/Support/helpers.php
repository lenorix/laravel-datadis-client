<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Internal\GuardLedgers;
use Lenorix\LaravelDatadisClient\Internal\Importer;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

const CUPS = FakesDatadis::CUPS;

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
    return (new Importer(app()))->lockName('00000000T');
}
