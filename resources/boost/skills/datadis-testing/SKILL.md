---
name: datadis-testing
description: Test code that uses lenorix/laravel-datadis-client by faking Datadis with Http::fake() - the login, supplies and data endpoints, the answer shapes, holders, failures and the 24 hour guard. Use when writing Pest or PHPUnit tests for Datadis integrations.
---

# Datadis Testing

## When to use this skill

Use it when writing tests for code that calls `DatadisClient`, `PublicApiClient` or the artisan commands. No test may reach the real Datadis: a consumption query it receives cannot be repeated for 24 hours.

## Setup

In the test environment (`APP_ENV=testing`) the package sends every call through Laravel's `Http` client, so `Http::fake()`, `Http::preventStrayRequests()` and `Http::assertSent()` work. Give the app an account and a cache that shares the token and the guard inside one test:

```php
config()->set('services.datadis.username', '00000000T'); // a valid NIF, NIE or CIF shape
config()->set('services.datadis.password', 'secret');
config()->set('cache.default', 'array');
Http::preventStrayRequests();   // a URL that stops matching must fail, not reach Datadis
```

Two things break it:

- `DATADIS_HTTP_STACK=guzzle` in a `.env` the tests load: the package refuses to build a client, because the test would reach the real Datadis. Leave it unset in tests.
- `Http::fake()` outside the test environment: the default there is plain Guzzle, which `Http::fake()` does not touch. Set `datadis-client.http.stack` to `laravel`, or give a Guzzle mock handler with `config()->set('datadis-client.http.options.handler', $handlerStack)`.

## Faking Datadis

One helper that answers every endpoint; add the bodies you need to assert on.

```php
function fakeDatadis(): void
{
    $list = fn (string $key) => Http::response([$key => [], 'distributorError' => []]);

    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response($jwt, 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => 'ES0000000000000000AA0A', 'distributor' => 'X', 'pointType' => 5,
            'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-contract-detail*' => $list('contract'),
        '*/get-consumption-data*' => $list('timeCurve'),
        '*/get-max-power*' => $list('maxPower'),
        '*/get-reactive-data*' => Http::response(['reactiveEnergy' => [], 'distributorError' => []]),
        '*/list-authorization*' => $list('authorizations'),
        '*/new-authorization*' => Http::response('Authorization created', 200, ['Content-Type' => 'text/plain']),
    ]);
}
```

- **The login** answers a JWT as text. Any `header.payload.signature` with a numeric `exp` claim in the future works; it is not verified. A token whose `exp` is in the past is not reused.
- **The paths** end in `-v2` for API v2 (`get-supplies-v2`); the wildcard after the endpoint name covers it and the query string.
- **Answer keys**: `supplies`, `contract`, `timeCurve`, `maxPower`, `reactiveEnergy`, `authorizations`, `groups`, `users`, always beside `distributorError`. The writes answer plain text.
- **Make the supply queryable**: a CUPS as `ES` plus 16 digits plus 2 letters, a `distributorCode` and a `pointType`.

## What to test

- **The 24 hour guard**: resolve the client twice (`app(DatadisClient::class)`), ask the same consumption twice, expect `RepetitionWindowException` the second time and only one consumption request: `Http::recorded(...)`.
- **Failures**: `Http::response('', 404)` gives `NoDataException`, a `503` gives `ServiceUnavailableException`; assert `requestSent`. Harmless reads are retried: use `Http::sequence()->push('', 503)->push($ok)`. Data queries and writes are not.
- **Holders**: after `forHolder($nif)` the URL carries `authorizedNif=<NIF>`; it is omitted for the account's own NIF.
- **Another account**: set `datadis-client.accounts.other`, then `LaravelDatadisClient::account('other')`.
- **Commands**: `Artisan::call('datadis:supplies')` and read `Artisan::output()`. A malformed input exits with code 1 and records no request.
- **Numbers**: compare decimal strings, never floats.

## Do not

- Do not answer everything with one catch-all `'*'` response: the login and the data endpoints have different bodies.
- Do not share a cache store between tests and a real environment: it would hold a real token and real guard entries.
- Do not call the real Datadis "just once" from a test.
