---
name: datadis-testing
description: Test code that uses lenorix/laravel-datadis-client with Http::fake(), including the login, supplies and data endpoints, the 24 hour guard and named accounts. Use when writing Pest or PHPUnit tests for Datadis integrations.
---

# Datadis Testing

## When to use this skill

Use it when writing tests for code that calls `DatadisClient`. No test may call the real Datadis: a consumption query it receives cannot be repeated for 24 hours.

## Setup

The package sends every call through Laravel's `Http` handler stack, so `Http::fake()`, `Http::preventStrayRequests()` and `Http::assertSent()` work. Set an account and the cache in the test environment (the `array` cache already shares the token and the guard inside one test):

@verbatim
```php
config()->set('services.datadis.username', '00000000T'); // a valid NIF/NIE/CIF shape
config()->set('services.datadis.password', 'secret');
config()->set('cache.default', 'array');
```
@endverbatim

## Faking Datadis

@verbatim
```php
Http::preventStrayRequests();
Http::fake([
    '*/nikola-auth/tokens/login' => Http::response($jwt, 200, ['Content-Type' => 'text/plain']),
    '*/api-private/api/get-supplies*' => Http::response(['supplies' => [[
        'cups' => 'ES0000000000000000AA0A', 'distributor' => 'X', 'pointType' => 5,
        'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
    ]], 'distributorError' => []]),
    '*/api-private/api/get-consumption-data*' => Http::response(['timeCurve' => [], 'distributorError' => []]),
]);
```
@endverbatim

- The login answers a JWT as text; any `header.payload.signature` with a numeric `exp` claim 24 h ahead works (it is not verified).
- Data endpoints (API v2 adds a `-v2` suffix: `get-supplies-v2`, `get-consumption-data-v2`) answer `{ "<list key>": [...], "distributorError": [] }`; list keys are `supplies`, `timeCurve`, `maxPower`, ... Use a wildcard (`*`) after the endpoint name for the query string.
- Use a CUPS the client accepts (`ES` + 16 digits + 2 letters, optional `0F`-style suffix) and a `distributorCode`/`pointType` so `isQueryable()` is true.

## What to test

- A repeated guarded query: build two clients (`app(DatadisClient::class)` twice) and expect `RepetitionWindowException` on the second, with only one request recorded: `Http::assertSentCount(...)`.
- Failures: `Http::response('', 404)` gives `NoDataException`; `Http::response(..., 503)` gives `ServiceUnavailableException`; assert `requestSent`.
- Another account: `LaravelDatadisClient::account('other')` after setting `datadis-client.accounts.other`.
- Never assert on real money or energy with floats: compare decimal strings.

## Do not

- Do not use `Http::fake()` with a catch-all `'*'` response for everything: the login and the data endpoints have different bodies.
- Do not share one cache store between a test and a real environment: it would hold a real token and real guard entries.
