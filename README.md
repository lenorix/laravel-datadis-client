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

Add it under `accounts` in `config/datadis-client.php` (a name with a dot, like `tenant.east`, works too):

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

### Read an invoice period

Datadis does not publish the billing day: the retailer sets it, and no field of the contract carries it. Give the client the dates of an invoice, or the day the cycle starts, and it tells which months to ask for and adds up the readings exactly:

```php
use Lenorix\DatadisClient\Time\BillingCycle;
use Lenorix\DatadisClient\Time\BillingPeriod;

$period = BillingCycle::monthlyFrom(15)->lastEndedPeriod(now());   // or BillingPeriod::between($firstDay, $lastDay) with the dates of the invoice

$months = $period->months();                                      // the months to ask Datadis for
$result = $client->getConsumptionDataOf($supply, $months[0], end($months));

$period->totalKWh($result->records);       // the exact total, as text
$period->isCoveredBy($result->records);    // false while Datadis has not published up to its last day
```

The dates of an invoice are the reliable source; a cycle from a day is a guess that the retailer may move.

This is a guarded query like any other, and it can be the same one as the daily refresh: on the days the refresh asks the previous month and the current one, a period over those two months is that very query, and whichever runs second throws `RepetitionWindowException`. If a daily refresh runs, compute the period from the readings it already returned (and stored), or catch the exception.

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

- **Use `redis` or `database` as your cache** (`CACHE_STORE`). The package keeps the token and the guard in Laravel's default cache and has no cache setting of its own. Redis, Memcached and the database are shared by every worker and server; `file` only coordinates processes on one server, and `array` lives in one process, so with it the guard cannot stop another worker. The `null` driver remembers nothing and is refused.
- **Set `DATADIS_LEDGER_KEY` if you rotate `APP_KEY`.** Without it the secret is derived from `APP_KEY`, and rotating the key makes the guard forget the last 24 hours.
- **Refresh the current month with `getLatestConsumptionDataOf()`** (and `getLatestMaxPowerOf()`) in a daily job. The guard keeps a query for 24 hours and 10 minutes, so asking the same range at the same time the next day is refused; these methods alternate the range from one day to the next, so nothing repeats (one exception, below). One run a day.
- **Never loop over a data query**, and never add a retry of your own around one.

### Know until when a query is blocked

A refused repeat carries the answer: `RepetitionWindowException::$startDate` and `$endDate` are the months the refused query asked for, whoever refused it: they tell a daily refresh, whose range the client picks, exactly which months it did not get. When the package refused the query, `$availableAt` is when the same query is allowed again, and `$lastAttemptAt` when it was last sent (both are `null` when Datadis itself answered with a 429). Its message says it too, so the log line shows it. To look without sending or claiming anything (a command, a screen), ask the client:

```php
$until = $client->consumptionDataOfBlockedUntil($supply, $month);   // ?DateTimeImmutable, null when it may be sent now
```

`maxPowerOfBlockedUntil()` and `reactiveDataOfBlockedUntil()` do the same. A job should call and catch the exception instead: that decides in one step.

### See what the guard does

Every query the guard lets go out, frees or imports is announced with a Laravel event, `Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged`, so you can keep a history or audit the guard without reading the cache:

```php
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged;

Event::listen(function (DatadisLedgerChanged $event) {
    // $event->kind: Claimed (it is going out), Released (it never left, so it may be sent again), Remembered (an import)
    // $event->account: the name of the account in `datadis-client.accounts`
    // $event->key, $event->at, $event->endpoint
});
```

It carries no personal data: `account` is the name, not the username (a NIF), `key` is the guard's keyed hash of the query (the same for the same query), and there is no CUPS. What a listener throws is ignored: a broken listener never decides whether a query goes.

There is **no event for a refusal**: the client has only those three kinds. A query the guard refuses is the `RepetitionWindowException` and its log line, which also say when it is allowed again and which months it asked for.

### Refresh the current month every day

Asking the same range every day is refused: the guard keeps a query for 24 hours and 10 minutes. For a daily job use the `getLatest` methods, which alternate the range from one day to the next:

```php
$supply = $client->findSupply(Cups::fromString($cups));

$consumption = $client->getLatestConsumptionDataOf($supply);   // the current month, or the previous one plus it
$maxPower = $client->getLatestMaxPowerOf($supply);
```

The current month is always in the range, and the previous month is added every second day. Run it once a day.

One exception, which comes from the client: **in the month the contract starts, the range is that month every day**, because there is no previous month to alternate with. A run within 24 hours and 10 minutes of the day before asks the same query and is refused (`RepetitionWindowException`), so that month is refreshed every second day, not every day. Catch the exception, as the job in the skill does. From the next month the range alternates again.

When the contract has nothing to refresh this month (it ended before it, or starts after it) the call throws `NothingToRefreshException`. Catch that type and not its parent `InvalidRequestException`, which would also hide a real mistake. The other refusals before sending have their own types too: `OutOfServedRangeException` (a month Datadis does not serve) and `OutOfContractRangeException` (a range outside the contract). Reactive energy has no such method (it shares its guard entry with maximum power): ask it for closed months. The `datadis-sync` Boost skill has a complete job.

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

- **`LaravelDatadisClient::rememberConsumption()`**, **`rememberMaxPower()`** and **`rememberReactive()`** (the facade and the manager, not the injected client) match the three queries the guard covers. Consumption takes the point type, and the measurement type and the holder (`authorizedNif:`) if you used them. Maximum power and reactive energy take only the CUPS, the distributor code and the months, which is all Datadis keys them on. They are the same entry for the guard: remembering one blocks the other, as sending one does.
- The order of the history does not matter, and it may hold the same query more than once: the guard keeps the newest attempt of each query, since its window is the one that ends last. A call returns `true` when it recorded the attempt, and `false` when the attempt is older than the window or the guard already holds this one or a newer one. It never takes a newer attempt back to an older time.
- The attempt is remembered for what is left of its window: one sent 23 hours ago blocks a repeat for one more hour and ten minutes. `at` must be a `DateTimeInterface` (a date column cast by Eloquent, not a string). A time more than ten minutes ahead of now, a reversed range or a value Datadis would not accept throws an `InvalidRequestException` before anything is recorded. A time that is wrongly in the past is **not** caught: a UTC wall clock read as Madrid time lands one or two hours early, and the protection then ends that much early, so Datadis would refuse and count the repeat. Check the timezone of the column; if you are unsure of it, pass `at: now()` (it over-protects, which is always safe).
- Use these three methods of `LaravelDatadisClient` for a history. The injected client has imports of its own (`rememberConsumptionData($sentAt, ...)`, `rememberMaxPower($sentAt, $cups, ...)`, `rememberMaxPowerOf($sentAt, $supply, ...)`...) with another order of arguments (the time first), and they do **not** take the lock: that includes the ones reached through the facade, which forwards what it does not define to the client.
- Pass `account: 'second'` for a named account. Without it the entries are kept for the default account only, and the other accounts stay unguarded.
- Each import runs under a lock on your cache, one for the account (named from a keyed hash, never from the NIF), so two imports cannot leave the older time. If another import does not finish within two seconds the call throws a `LedgerUnavailableException`. On a store without locks, import from one process.
- **Stop the workers while you import** (pause the queue and the scheduler). The import never moves back a newer attempt that it reads, but the lock only serialises imports against each other: a worker that sends at the exact moment of an overwrite can lose its time. Only the pause rules that out.
- Include the queries Datadis rejected and the ones that timed out: it counts them too.
- If you cannot be sure the old record is complete (a crashed worker, a query sent from another tool), wait the whole window.
- Keep the old record until the window has passed, and do not send guarded queries from both systems at once.

### Failures and retries

Network errors and `502`, `503` and `504` answers are retried twice, with backoff, for the login, the lists and the other reads. Consumption, maximum power, reactive energy and every call that changes data are never retried, because Datadis may already have counted them. Set `DATADIS_HTTP_RETRIES=0` to turn retries off.

A `401` is different: the client logs in again and repeats only the calls that are safe to repeat. A guarded query, an authorization or a partner user change is not sent again: it fails with an `AuthenticationException` whose `requestSent` is `true`. The open data walks (`apiSearchAll()`, `apiSearchAutoAll()`) throw a `PageLimitReachedException` after the last record when they stop at their page limit with a full last page, since more records may remain.

### Your password and token

The calls go through plain Guzzle, so Laravel's HTTP events, global middleware and recorders (Telescope, Nightwatch) never see the login password or the token. Keep it that way outside tests.

The token is kept in Laravel's default cache, which the package uses without Laravel's cache events, so the cache watchers do not record it either. The store itself still holds it: protect it like a password.

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
- The package takes its time from the application, so `travelTo()` and `Carbon::setTestNow()` move the guard, the range of the daily refresh and the life of the token together: a test can run a daily job over several days. Give the fake token an `exp` from `now()` (`now()->addDay()->timestamp`), not from `time()`, and answer the login with a closure (`'*/nikola-auth/tokens/login' => fn () => Http::response(...)`): a fixed body stays expired once the test has travelled. This works on a cache whose expiry follows `now()` (`array`, `file`, `database`); on Redis or Memcached a held key keeps its own time, and the guard still refuses it after the travelled window, so run the tests that travel on `array`.
- To fake Datadis outside `testing`, set `DATADIS_HTTP_STACK=laravel`, or give a Guzzle mock handler with `config()->set('datadis-client.http.options.handler', $handlerStack)`.

## Configuration

All keys are in `config/datadis-client.php`.

| Key | What it does | Default |
|---|---|---|
| `default` | Account used by the container, the facade and the commands (`DATADIS_ACCOUNT`). | `default` |
| `accounts.*` | `username`, `password`, `api_version` (`v1`/`v2`, `DATADIS_API_VERSION`), `timezone` (`DATADIS_TIMEZONE`), `timeout` (`DATADIS_TIMEOUT`), `connect_timeout` (`DATADIS_CONNECT_TIMEOUT`), `base_url`, `user_agent`, `check_username_control`. The account named `default` takes its credentials from `services.datadis`. | `v2`, `Europe/Madrid`, 120 s, 10 s |
| `ledger.key` | Secret of at least 16 bytes for the guard (`DATADIS_LEDGER_KEY`). | from `APP_KEY` |
| `http.stack` | `guzzle` or `laravel` (`DATADIS_HTTP_STACK`). `laravel` lets Laravel's events and recorders see the password and the token. | `guzzle`; `laravel` in tests |
| `http.retries` | `max` (0 to 10, `DATADIS_HTTP_RETRIES`), `base_delay_ms`, `max_delay_ms`. | 2, 1000, 30000 |
| `http.options` | Extra Guzzle options for every call: a proxy, `verify`... They replace the package's own `timeout` and `connect_timeout`. `debug` is refused, because it would print the login password. | none |
| `report_level` | PSR-3 log level of a refused repeat (`DATADIS_REPORT_LEVEL`), or `null` to leave your exception handler alone. | `warning` |

## Laravel Boost

The package ships [Laravel Boost](https://laravel.com/docs/boost) resources, so your coding agent learns how to call Datadis, which calls count against the 24 hour rule, how to write sync jobs and how to test them.

Run `php artisan boost:install` and, when Boost asks which third-party guidelines and skills to install, pick `lenorix/laravel-datadis-client`. Without interaction (CI, scripts) Boost only installs what `boost.json` lists: add `"packages": ["lenorix/laravel-datadis-client"]` to it and run `php artisan boost:update`; its four skills (`datadis-development`, `datadis-electricity-domain`, `datadis-sync`, `datadis-testing`) come with the package. The guideline lands in `CLAUDE.md` and `AGENTS.md`, and the skills in `.claude/skills` and `.agents/skills`.

- **Guideline** (always loaded): conventions and the rules not to break.
- **`datadis-development`**: calls, results and errors.
- **`datadis-electricity-domain`**: the Spanish electricity rules behind the data: CUPS, tariffs, periods, power and reactive energy. Every rule cites its official source (the BOE text of the Circular CNMC 3/2020 and of the RD 1110/2007, the CNMC and the Datadis manual).
- **`datadis-sync`**: scheduled jobs and backfills.
- **`datadis-testing`**: faking Datadis in tests.

## Contributing, security and license

To contribute, read [CONTRIBUTING](.github/CONTRIBUTING.md). Report a vulnerability privately, as the [security policy](.github/SECURITY.md) explains. See the [changelog](CHANGELOG.md).

Created by [Jesus Hernandez](https://github.com/jhg) and [contributors](https://github.com/lenorix/laravel-datadis-client/graphs/contributors). Released under [The Unlicense](LICENSE.md), in the public domain.
