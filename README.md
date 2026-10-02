# Datadis client for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)

Laravel integration of [`lenorix/datadis-client`](https://github.com/lenorix/datadis-php-client), the client for [Datadis](https://datadis.es) (supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy). It wires the client to what your application already has:

- **HTTP**: plain Guzzle with the package's settings, so Laravel's events and recorders never see your Datadis password and token. In the test environment the calls go through Laravel's `Http` handler stack instead, so `Http::fake()` and `Http::assertSent()` work (see [Security](#security)).
- **Cache**: the login token and the **24 hour guard** (Datadis refuses an identical query for 24 hours and counts the refused ones) live in a Laravel cache store shared by all workers. Recording a query is atomic (`Cache::add()`), so two workers never both send it.
- **Configuration**: one or more accounts in `config/datadis-client.php`, container binding, facade and an artisan command.

## Installation

```bash
composer require lenorix/laravel-datadis-client
php artisan vendor:publish --tag="datadis-client-config"
```

Requires PHP 8.4 and Laravel 13 (`lenorix/datadis-client` needs Guzzle 8, which Laravel 11 and 12 do not allow). Datadis is a third-party service, so its credentials go in `config/services.php`, like any other:

```php
'datadis' => [
    'username' => env('DATADIS_USERNAME'),
    'password' => env('DATADIS_PASSWORD'),
],
```

```dotenv
DATADIS_USERNAME=A00000000
DATADIS_PASSWORD=your-password
```

`services.datadis` also accepts the other account settings (`api_version`, `timezone`, `timeout`...) and wins over the account named `default` in `config/datadis-client.php`, which keeps the same `DATADIS_*` variables as a fallback and holds extra accounts.

## Security

**The calls do not go through Laravel's `Http` client unless you ask.** The login request carries your Datadis **password** and its answer carries the **token**; Laravel's request events, global HTTP middleware and recorders (Telescope, Nightwatch, your own logging middleware) would see both. So, unset, `datadis-client.http.stack` is `guzzle`: plain Guzzle with the package's settings, where nothing of Laravel sees the calls.

The test environment (`APP_ENV=testing`) is the exception: it uses `laravel` so that `Http::fake()` works. Do not set `DATADIS_HTTP_STACK=guzzle` in a `.env` your tests load (or override it in `phpunit.xml`): the package refuses to build a client on plain Guzzle in tests, because `Http::fake()` would not apply and a test would reach the real Datadis. To fake Datadis in another environment, set `DATADIS_HTTP_STACK=laravel` and keep in mind what the recorders there will see. The token is also kept in your cache store: protect that store like a password. Details in [Logging and request recorders](#logging-and-request-recorders).

## Usage

Inject the client, or use the facade, which forwards to the default account:

```php
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

public function __invoke(DatadisClient $client)
{
    $supply = $client->findSupply(Cups::fromString('ES0000000000000000AA0A'));
    $result = $client->getConsumptionDataOf($supply, Month::of(2026, 7));
}

$supplies = Datadis::getSupplies();
```

See the [client documentation](https://github.com/lenorix/datadis-php-client#readme) for every call, the results and the errors.

### Several accounts and holders

```php
use Lenorix\DatadisClient\Values\Nif;

Datadis::account('other')->getSupplies();

$holder = Datadis::forHolder(Nif::fromString('00000000T')); // someone who authorized your account
```

Define each account under `accounts` in `config/datadis-client.php`, with the same keys as `default`:

```php
'accounts' => [
    'default' => [/* ... */],
    'second' => [
        'username' => env('DATADIS_SECOND_USERNAME'),
        'password' => env('DATADIS_SECOND_PASSWORD'),
        'timezone' => 'Atlantic/Canary',
    ],
],
```

Only the account named `default` reads `services.datadis`.

### Public open data

Aggregated consumption by region, tariff and sector. Datadis still asks for an account's token, which it shares with the private client:

```php
use Lenorix\DatadisClient\PublicApiClient;

app(PublicApiClient::class)->apiSearch($query);   // or Datadis::publicApi('other')
```

### Command

```bash
php artisan datadis:supplies [--account=other] [--holder=00000000T]
```

### Logging and request recorders

With `DATADIS_HTTP_STACK=laravel` (the default only in the test environment), Laravel's request events, global HTTP middleware and recorders such as Telescope or Nightwatch see the login request (the password) and its answer (the token). Outside tests keep the default (`guzzle`), or exclude `datadis.es` from the recorders and from the request bodies they keep. With `guzzle`, `Http::fake()` does not apply to these calls: fake Datadis in tests, where the stack is `laravel`.

### Authorizations and partner accounts: read and write

The package gives the whole client, so besides reading supplies, contracts and data you can also manage access from Laravel:

```php
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

$nif = Nif::fromString('12345678Z');

Datadis::newAuthorization($nif);                                  // let someone read all your supplies
Datadis::newAuthorization($nif, $from, $to, Cups::fromString($cups)); // or some, for a period
Datadis::cancelAuthorization($nif);
Datadis::listAuthorization();                                     // who can read what
Datadis::getGroups();                                             // API v2
Datadis::partnerUserList();                                       // partner accounts
Datadis::partnerDeleteUser($nif);
Datadis::partnerAgreementDate();
```

Every method of the client works the same through the facade, the injected `DatadisClient` and `Datadis::account('name')`; the open data ones (`apiSearch()`, `apiSumSearch()`, `apiSearchAuto()`, `apiSumSearchAuto()`, `apiSearchAll()`, `apiSearchAutoAll()`) through `Datadis::publicApi()` or the injected `PublicApiClient`. The test suite calls each of them, and fails if the client gains a method it does not cover.

The calls that change data (`newAuthorization()`, `cancelAuthorization()`, `partnerDeleteUser()`) are never retried, and Datadis documents the authorization ones for API v1 with answers that are not verified yet: check the result text they return.

### Queued jobs

The client holds a password and cannot be serialised: resolve it in `handle()`, never keep it in a property.

## Configuration

| Key | Purpose |
|---|---|
| `default` | Account used by the binding, the facade and the command (`DATADIS_ACCOUNT`). |
| `accounts.*` | `username`, `password`, `api_version` (`v1`/`v2`), `timezone`, `timeout`, `connect_timeout`, `base_url`, `user_agent`, `check_username_control` (`false` accepts a username whose NIF/NIE/CIF control character does not match). |
| `cache.store` | Store for token and guard (`DATADIS_CACHE_STORE`); default store if empty, and anything that is not a store name fails. Use Redis, Memcached, database or DynamoDB for several servers; `file` locks the file, so it only coordinates processes on one host, and `array` lives in one process and protects nothing across workers. It holds the token, so protect it like a password. |
| `http.stack` | `guzzle` (the default, except in the test environment) or `laravel` (`Http::fake()` works, but Laravel's events and recorders see the login password and token): `DATADIS_HTTP_STACK`. |
| `http.options` | Extra Guzzle options for every call (a proxy, `verify`...), merged over the package's own settings. |
| `report_level` | Log level of a refused repeat (`RepetitionWindowException`), `warning` by default (`DATADIS_REPORT_LEVEL`); one of the PSR-3 levels, anything else fails when the client is built. It is set after your own `withExceptions()`, so it wins over a level you set there; use `null` to leave your handler alone. |
| `ledger.key` | Secret of the guard's keyed hash, at least 16 bytes (`DATADIS_LEDGER_KEY`); derived from `APP_KEY` if empty. Changing it forgets the queries already made. |

A repeated query fails with `RepetitionWindowException` before anything is sent.

**Set `DATADIS_LEDGER_KEY`.** Derived from `APP_KEY`, the secret changes whenever you rotate the application key, and the guard forgets the queries of the last 24 hours, so they can be sent (and counted) again.

## Laravel Boost

The package ships [Laravel Boost](https://laravel.com/docs/boost) resources, so your coding agent learns the client and the Datadis rules (the 24 hour query rule, hour labels, errors) when you run `php artisan boost:install` or `boost:update --discover`:

- `resources/boost/guidelines/core.blade.php`: the conventions, always loaded.
- Skills, loaded on demand: `datadis-development` (calls, results, errors), `datadis-sync` (scheduled jobs and backfills) and `datadis-testing` (`Http::fake()`).

## Testing

In the test environment (`APP_ENV=testing`) the calls go through Laravel's `Http` client, so you can fake Datadis. Elsewhere the default stack is plain Guzzle: set `DATADIS_HTTP_STACK=laravel`, or give it your own Guzzle handler at runtime with `config()->set('datadis-client.http.options.handler', $handlerStack)` (not in a config file: an object there breaks `config:cache`).

```php
Http::preventStrayRequests();   // a URL that stops matching must fail, not reach Datadis
Http::fake([
    '*/nikola-auth/tokens/login' => Http::response($token, 200, ['Content-Type' => 'text/plain']),
    '*/api-private/api/get-supplies*' => Http::response(['supplies' => [...], 'distributorError' => []]),
]);
```

```bash
composer test            # 200 property cases each; DATADIS_PBT_ITERATIONS=n changes it
composer test-pbt        # 2000 cases each
composer test-coverage   # fails under 100 % of src/
composer audit
```

`composer.lock` is not versioned, as is usual for a library: CI resolves the newest and the lowest allowed dependencies on every run and audits them.

## Credits

- [Jesus Hernandez](https://github.com/jhg)
- [All Contributors](https://github.com/lenorix/laravel-datadis-client/graphs/contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md).
