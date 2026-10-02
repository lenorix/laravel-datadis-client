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

Two helpers: a fake token, and one that answers every endpoint of the client (add the bodies you need to assert on).

```php
function fakeJwt(): string
{
    $encode = fn (array $claims) => rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    // header.payload.signature, with an exp claim 24 hours ahead; the signature is never checked
    return $encode(['alg' => 'HS512']).'.'.$encode(['exp' => time() + 86400]).'.signature';
}

function fakeDatadis(): void
{
    $list = fn (string $key) => Http::response([$key => [], 'distributorError' => []]);
    $text = fn (string $body) => Http::response($body, 200, ['Content-Type' => 'text/plain']);

    Http::fake([
        '*/nikola-auth/tokens/login' => $text(fakeJwt()),
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => 'ES0000000000000000AA0A', 'distributor' => 'X', 'pointType' => 5,
            'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-distributors-with-supplies*' => Http::response(['distributorError' => []]),
        '*/get-contract-detail*' => $list('contract'),
        '*/get-consumption-data*' => $list('timeCurve'),
        '*/get-max-power*' => $list('maxPower'),
        '*/get-reactive-data*' => Http::response(['reactiveEnergy' => [], 'distributorError' => []]),
        '*/get-groups*' => $list('groups'),
        '*/list-authorization*' => $list('authorizations'),
        '*/new-authorization*' => $text('Authorization created'),
        '*/cancel-authorization*' => $text('Authorization cancelled'),
        '*/partner-user-list*' => $list('users'),
        '*/partner-delete-user*' => $text('User unlinked'),
        '*/partner-agreement-date*' => Http::response(['partnerAgreementDate' => null]),
        '*/api-public/api-*' => Http::response([]),   // the open data
    ]);
}
```

- **The login** answers a JWT as text (`fakeJwt()` above). Any `header.payload.signature` with a numeric `exp` claim a few minutes ahead works; the signature is not verified. A token that has expired, or is about to (within about two minutes), is not reused: the client logs in again.
- **The paths** end in `-v2` for API v2 (`get-supplies-v2`); the wildcard after the endpoint name covers it and the query string.
- **Answer keys**: `supplies`, `contract`, `timeCurve`, `maxPower`, `reactiveEnergy`, `authorizations`, `groups`, `users`, always beside `distributorError`. The writes answer plain text.
- **Make the supply queryable**: a CUPS as `ES` plus 16 digits plus 2 letters, a `distributorCode` and a `pointType`.

## What to test

- **The 24 hour guard**: resolve the client twice (`app(DatadisClient::class)`), ask the same consumption twice, expect `RepetitionWindowException` the second time and only one consumption request: `Http::recorded(...)`.
- **Failures**: `Http::response('', 404)` gives `NoDataException`, a `503` gives `ServiceUnavailableException`; assert `requestSent`. Harmless reads are retried: use `Http::sequence()->push('', 503)->push($ok)`. The retries wait for real (1 and 2 seconds by default), so in tests set `config()->set('datadis-client.http.retries', ['max' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 1])`, or `['max' => 0]` where a failed call must fail at once. Data queries and writes are never retried.
- **Holders**: after `forHolder($nif)` the URL carries `authorizedNif=<NIF>`; it is omitted for the account's own NIF.
- **Another account**: set `datadis-client.accounts.other`, then `LaravelDatadisClient::account('other')`.
- **Commands**: `Artisan::call('datadis:supplies')` and read `Artisan::output()`. A malformed input exits with code 1 and records no request.
- **Numbers**: compare decimal strings, never floats.

## Do not

- Do not answer everything with one catch-all `'*'` response: the login and the data endpoints have different bodies.
- Do not share a cache store between tests and a real environment: it would hold a real token and real guard entries.
- Do not call the real Datadis "just once" from a test.

## Sources

- The endpoints, parameters and answer shapes to fake: the Datadis API manual, in the API section of [datadis.es](https://datadis.es/private-api) (it asks for a Datadis login), and the answers recorded by the client ([API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md)).
- Faking in Laravel: [Faking Responses](https://laravel.com/docs/13.x/http-client#faking-responses), [Faking Response Sequences](https://laravel.com/docs/13.x/http-client#faking-response-sequences) and [Preventing Stray Requests](https://laravel.com/docs/13.x/http-client#preventing-stray-requests).
