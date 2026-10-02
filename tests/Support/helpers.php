<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Time\Month;

const CUPS = 'ES0000000000000000AA0A';

function fakeToken(): string
{
    $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    return $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'a', 'iat' => time(), 'exp' => time() + 86400])).'.sig';
}

function fakeDatadis(): void
{
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
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
