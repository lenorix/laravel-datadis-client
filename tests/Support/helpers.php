<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

const CUPS = 'ES0000000000000000AA0A';

function fakeToken(): string
{
    $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    return $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'a', 'iat' => now()->timestamp, 'exp' => now()->addDay()->timestamp])).'.sig';
}

function fakeDatadis(): void
{
    Http::fake([
        '*/nikola-auth/tokens/login' => fn () => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/api-private/api/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2',
            'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'postalCode' => '28001',
        ]], 'distributorError' => []]),
        '*/api-private/api/get-contract-detail*' => Http::response(['contract' => [], 'distributorError' => []]),
        '*/api-private/api/get-max-power*' => Http::response(['maxPower' => [], 'distributorError' => []]),
        '*/api-public/api-search*' => Http::response([]),
        '*/api-private/api/get-consumption-data*' => Http::response(['timeCurve' => [], 'distributorError' => []]),
    ]);
}

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
    Http::swap(new Factory);
    Http::preventStrayRequests();
    app('cache')->store()->clear();
    fakeDatadis();
}

/** Datadis answering every endpoint of the client. */
function fakeEverything(): void
{
    $list = fn (string $key) => Http::response([$key => [], 'distributorError' => []]);
    $text = fn (string $body) => Http::response($body, 200, ['Content-Type' => 'text/plain']);

    Http::fake([
        '*/nikola-auth/tokens/login' => fn () => $text(fakeToken()),
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-distributors-with-supplies*' => Http::response(['distributorError' => []]),
        '*/get-contract-detail*' => $list('contract'),
        '*/get-consumption-data*' => $list('timeCurve'),
        '*/get-max-power*' => $list('maxPower'),
        '*/get-reactive-data*' => Http::response(['reactiveEnergy' => [], 'distributorError' => []]),
        '*/new-authorization*' => $text('created'),
        '*/cancel-authorization*' => $text('cancelled'),
        '*/list-authorization*' => $list('authorizations'),
        '*/get-groups*' => $list('groups'),
        '*/partner-user-list*' => $list('users'),
        '*/partner-delete-user*' => $text('unlinked'),
        '*/partner-agreement-date*' => Http::response(['partnerAgreementDate' => null]),
        '*/api-public/api-*' => Http::response([]),
    ]);
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
    $ledger = (fn () => $this->ledger())->call(app(Manager::class));

    return $ledger->lastAttempt('00000000T', ['cups' => CUPS, 'distributorCode' => '2', 'startDate' => $month()->format(), 'endDate' => $month()->format(), 'authorizedNif' => null])?->getTimestamp();
}

/** The key of the lock an import takes for the default account: a keyed hash of its username, never the NIF. */
function importLockName(): string
{
    $manager = app(Manager::class);

    return 'datadis_import_'.substr(hash_hmac('sha256', '00000000T', (fn () => $this->ledgerKey())->call($manager)), 0, 40);
}
