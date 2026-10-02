# Datadis client for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)

Read your electricity data from [Datadis](https://datadis.es) in Laravel: supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy, authorizations and open data.

It wraps [`lenorix/datadis-client`](https://github.com/lenorix/datadis-php-client) and connects it to your app:

- **Inject it or use the facade.** One or several Datadis accounts, and supplies of third parties who authorized you.
- **The 24 hour rule is handled.** Datadis refuses the same data query for 24 hours. The package remembers every query in your cache, so no worker or job repeats one.
- **Safe by default.** Your Datadis password and token do not pass through Laravel's HTTP or cache events, Telescope or Nightwatch.
- **Failures are handled.** Harmless reads are retried after network errors; data queries never are.
- **Artisan commands and Laravel Boost guidelines** are included.

Requires PHP 8.4 and Laravel 13 (Laravel 11 and 12 are not supported: `lenorix/datadis-client` needs Guzzle 8).

## Installation

```bash
composer require lenorix/laravel-datadis-client
```

Datadis is a third-party service, so its credentials go in `config/services.php`:

```php
'datadis' => [
    'username' => env('DATADIS_USERNAME'),   // the NIF, NIE or CIF you registered with
    'password' => env('DATADIS_PASSWORD'),
],
```

```dotenv
DATADIS_USERNAME=A00000000
DATADIS_PASSWORD=your-password
DATADIS_LEDGER_KEY=
```

Fill `DATADIS_LEDGER_KEY` with a random secret, for example the output of `php -r "echo bin2hex(random_bytes(32));"`. It keys the 24 hour guard.

Check it works:

```bash
php artisan datadis:supplies
```

To change any other setting, publish the config: `php artisan vendor:publish --tag="datadis-client-config"`.

## Quick start

Every data call needs the supply as Datadis lists it, so look it up first:

```php
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

class ConsumptionController
{
    public function __invoke(DatadisClient $datadis)
    {
        $supply = $datadis->findSupply(Cups::fromString('ES0000000000000000AA0A'));

        abort_unless($supply?->isQueryable(), 404);

        $result = $datadis->getConsumptionDataOf($supply, Month::of(2026, 7));

        foreach ($result->records as $reading) {
            echo $reading->start?->format('Y-m-d H:i'), ' ', $reading->consumptionKWh, " kWh\n";
        }
    }
}
```

The facade forwards to the same client:

```php
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

Datadis::getSupplies();
```

Every call, result and error is documented in the [client's README](https://github.com/lenorix/datadis-php-client#readme).

## Common tasks

### Use another account

Add it under `accounts` in `config/datadis-client.php`:

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

```php
Datadis::account('second')->getSupplies();
```

### Read the supplies of someone who authorized you

```php
use Lenorix\DatadisClient\Values\Nif;

$holder = Datadis::forHolder(Nif::fromString('12345678Z'));

$holder->getSupplies();
```

### Give or take away access to your supplies

```php
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

$nif = Nif::fromString('12345678Z');

Datadis::newAuthorization($nif);                                       // all your supplies
Datadis::newAuthorization(                                             // or some, for a period
    $nif,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-12-31'),
    Cups::fromString('ES0000000000000000AA0A'),
);
Datadis::cancelAuthorization($nif);
Datadis::listAuthorization();
```

These change data on Datadis and are never retried. Datadis documents them for API v1 and their answers are not verified yet: check the text they return.

### Read the open data

Aggregated consumption by region, tariff and sector:

```php
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;

$query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);

app(PublicApiClient::class)->apiSearch($query);   // or Datadis::publicApi()
```

### Use it from the terminal

```bash
php artisan datadis:supplies
php artisan datadis:contract ES0000000000000000AA0A
php artisan datadis:consumption ES0000000000000000AA0A 2026-07 --to=2026-09 --quarter-hourly
php artisan datadis:authorizations
php artisan datadis:authorize 12345678Z --cups=ES0000000000000000AA0A --from=2026-01-01 --to=2026-12-31
php artisan datadis:authorization:cancel 12345678Z
```

All of them take `--account=second`. The ones that read (`supplies`, `contract`, `consumption`) also take `--holder=12345678Z`, to read the supplies of someone who authorized you. The authorization commands act for the account itself, so they have no `--holder` (it would suggest the operation is made for that holder); `datadis:authorizations` takes `--owner=` to list only the authorizations given by one owner. A malformed NIF, CUPS, month or date, and a month range Datadis does not serve, fail with a message and send nothing.

The exit code tells a script what happened:

- **0**: it worked, including an empty answer (nothing published yet) and a distributor error beside real data, which only prints a warning.
- **1**: a wrong input, a Datadis error (a refused repeat of a guarded query, `RepetitionWindowException`, included: the query was not sent), or a distributor that failed so that no data came back.

## What to know before production

### The 24 hour rule

Datadis refuses an identical consumption or maximum power query for 24 hours, and counts the refused ones too. The package applies the same rule to reactive energy, to be safe. The package stops a repeat before sending it and throws `RepetitionWindowException`, which is reported as a warning.

- **Use a shared cache store** for `DATADIS_CACHE_STORE`: Redis, Memcached or your database. `file` only coordinates processes on one server, and `array` only protects within one process, not across workers.
- **Set `DATADIS_LEDGER_KEY`.** Without it the secret is derived from `APP_KEY`, and rotating the key makes the guard forget the last 24 hours.
- **Schedule repeats every second day.** The guard keeps a query for 24 hours and 10 minutes, so asking the same one at the same time the next day is refused.
- **Never loop over a data query**, and never add a retry of your own around one.

### Moving from your own record of queries

The guard only knows the queries sent through this package. If your app kept its own record before (a table, say), the first run after the switch could repeat a query sent in the last 24 hours and 10 minutes. Datadis would refuse it, and count it.

Before any worker sends a guarded query with the new client, do one of these:

- **Wait** 24 hours and 10 minutes without sending any.
- **Or tell the guard about the recent queries**, once, with the time each was sent:

```php
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

foreach (SentQuery::where('sent_at', '>', now()->subHours(25))->get() as $sent) {
    Datadis::rememberConsumption(
        Cups::fromString($sent->cups),
        $sent->distributor_code,
        $sent->point_type,
        Month::fromString($sent->start_month),   // YYYY/MM, as Datadis takes it; Month::of($year, $month) otherwise
        Month::fromString($sent->end_month),
        at: $sent->sent_at,
    );
}
```

- **`rememberConsumption()`**, **`rememberMaxPower()`** and **`rememberReactive()`** match the three queries the guard covers. Consumption takes the point type, and the measurement type and the holder (`authorizedNif:`) if you used them. Maximum power and reactive energy take only the CUPS, the distributor code and the months, which is all Datadis keys them on. They are the same entry for the guard: remembering one blocks the other, as sending one does.
- The order of the history does not matter, and it may hold the same query more than once: the guard keeps the newest attempt of each query, since its window is the one that ends last. A call returns `true` when it recorded the attempt, and `false` when the attempt is older than the window or the guard already holds this one or a newer one. It never takes a newer attempt back to an older time.
- The attempt is remembered for what is left of its window: one sent 23 hours ago blocks a repeat for one more hour and ten minutes. `at` must be a `DateTimeInterface` (a date column cast by Eloquent, not a string). A time more than ten minutes ahead of now throws before anything is recorded. A time that is wrongly in the past is **not** caught: a UTC wall clock read as Madrid time lands one or two hours early, and the protection then ends that much early, so Datadis would refuse and count the repeat. Check the timezone of the column; if you are unsure of it, pass `at: now()` (it over-protects, which is always safe).
- Pass `account: 'second'` for a named account. Without it the entries are kept for the default account only, and the other accounts stay unguarded.
- Each query is imported under a lock on your cache store, so two imports of the same query cannot leave the older time. If another import does not finish within two seconds the call throws a `LedgerUnavailableException`. On a store without locks, import from one process. The lock needs your cache store to be a Laravel one (`Cache::extend()` repositories are); a custom repository of another class makes the call throw a `ConfigurationException`, since it could not be given a lifetime.
- **Stop the workers while you import** (pause the queue and the scheduler). The import never moves back a newer attempt that it reads, but the lock only serialises imports against each other: a worker that sends at the exact moment of an overwrite can lose its time. Only the pause rules that out.
- Include the queries Datadis rejected and the ones that timed out: it counts them too.
- If you cannot be sure the old record is complete (a crashed worker, a query sent from another tool), wait the whole window.
- Keep the old record until the window has passed, and do not send guarded queries from both systems at once.

### Failures and retries

Network errors and `502`, `503` and `504` answers are retried twice, with backoff, for the login, the lists and the other reads. Consumption, maximum power, reactive energy and every call that changes data are never retried, because Datadis may already have counted them. Set `DATADIS_HTTP_RETRIES=0` to turn retries off.

### Your password and token

The calls go through plain Guzzle, so Laravel's HTTP events, global middleware and recorders (Telescope, Nightwatch) never see the login password or the token. Keep it that way outside tests.

The token is kept in your cache store, which the package uses without Laravel's cache events, so the cache watchers do not record it either. The store itself still holds it: protect it like a password.

### Queued jobs

The client holds a password and cannot be serialised. Type-hint it in `handle()`, never in the constructor or a property. For a backfill, dispatch one job per month with `$tries = 1`.

## Testing your application

In the test environment (`APP_ENV=testing`) the calls go through Laravel's `Http` client, so fake Datadis as any other service:

```php
Http::preventStrayRequests();   // a URL that stops matching must fail, not reach Datadis
Http::fake([
    '*/nikola-auth/tokens/login' => Http::response($jwt, 200, ['Content-Type' => 'text/plain']),
    '*/api-private/api/get-supplies*' => Http::response(['supplies' => [/* rows */], 'distributorError' => []]),
]);
```

- Do not set `DATADIS_HTTP_STACK=guzzle` in a `.env` your tests load: the package refuses to build the client, because a test would reach the real Datadis.
- Harmless reads are retried with real waits (1 and 2 seconds by default). In tests that fail a call, set `config()->set('datadis-client.http.retries', ['max' => 0])`, or the delays to 1 ms (`'base_delay_ms' => 1, 'max_delay_ms' => 1`), so they do not sleep.
- To fake Datadis outside `testing`, set `DATADIS_HTTP_STACK=laravel`, or give a Guzzle mock handler with `config()->set('datadis-client.http.options.handler', $handlerStack)`.

## Configuration

All keys are in `config/datadis-client.php`.

| Key | What it does | Default |
|---|---|---|
| `default` | Account used by the container, the facade and the commands (`DATADIS_ACCOUNT`). | `default` |
| `accounts.*` | `username`, `password`, `api_version` (`v1`/`v2`, `DATADIS_API_VERSION`), `timezone` (`DATADIS_TIMEZONE`), `timeout` (`DATADIS_TIMEOUT`), `connect_timeout` (`DATADIS_CONNECT_TIMEOUT`), `base_url`, `user_agent`, `check_username_control`. The account named `default` takes its credentials from `services.datadis`. | `v2`, `Europe/Madrid`, 120 s, 10 s |
| `cache.store` | Cache store for the token and the 24 hour guard (`DATADIS_CACHE_STORE`). A value that is not a text fails, and so does a name that is not a defined store or a store of the `null` driver, which remembers nothing. | default store |
| `ledger.key` | Secret of at least 16 bytes for the guard (`DATADIS_LEDGER_KEY`). | from `APP_KEY` |
| `http.stack` | `guzzle` or `laravel` (`DATADIS_HTTP_STACK`). `laravel` lets Laravel's events and recorders see the password and the token. | `guzzle`; `laravel` in tests |
| `http.retries` | `max` (0 to 10, `DATADIS_HTTP_RETRIES`), `base_delay_ms`, `max_delay_ms`. | 2, 1000, 30000 |
| `http.options` | Extra Guzzle options for every call: a proxy, `verify`... They replace the package's own `timeout` and `connect_timeout`. `debug` is refused, because it would print the login password. | none |
| `report_level` | PSR-3 log level of a refused repeat (`DATADIS_REPORT_LEVEL`), or `null` to leave your exception handler alone. | `warning` |

## Laravel Boost

The package ships [Laravel Boost](https://laravel.com/docs/boost) resources, so your coding agent learns how to call Datadis, which calls count against the 24 hour rule, how to write sync jobs and how to test them.

Run `php artisan boost:install` (or `boost:update --discover` later) and, when Boost asks which of your packages to include, pick `lenorix/laravel-datadis-client`. Boost does not choose third-party packages by itself when it runs without interaction (CI, scripts): there, list the package under `packages` and its four skills under `skills` in `boost.json` (`datadis-development`, `datadis-electricity-domain`, `datadis-sync`, `datadis-testing`) and run `php artisan boost:update`. The guideline lands in `CLAUDE.md` and `AGENTS.md`, and the skills in `.claude/skills` and `.agents/skills`.

- **Guideline** (always loaded): conventions and the rules not to break.
- **`datadis-development`**: calls, results and errors.
- **`datadis-electricity-domain`**: the Spanish electricity rules behind the data: CUPS, tariffs, periods, power and reactive energy. Every rule cites its official source (the BOE text of the Circular CNMC 3/2020 and of the RD 1110/2007, the CNMC and the Datadis manual).
- **`datadis-sync`**: scheduled jobs and backfills.
- **`datadis-testing`**: faking Datadis in tests.

## Contributing, security and license

To contribute, read [CONTRIBUTING](.github/CONTRIBUTING.md). Report a vulnerability privately, as the [security policy](.github/SECURITY.md) explains. See the [changelog](CHANGELOG.md).

Created by [Jesus Hernandez](https://github.com/jhg) and [contributors](https://github.com/lenorix/laravel-datadis-client/graphs/contributors). Released under the [MIT License](LICENSE.md).
