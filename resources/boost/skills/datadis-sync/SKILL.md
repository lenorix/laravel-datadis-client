---
name: datadis-sync
description: Build scheduled jobs, commands and backfills that sync Datadis consumption, maximum power or reactive data in Laravel without wasting the 24 hour query rule - one job per month, sized timeouts, no retries on data queries. Use when writing queued jobs, schedulers or importers on top of lenorix/laravel-datadis-client.
---

# Datadis Sync

## When to use this skill

Use it for anything that fetches Datadis data on a schedule or in bulk: a nightly sync, a historical backfill of up to 24 months, a per-customer importer. For single calls and results read `datadis-development`.

## The 24 hour rule

Datadis refuses an identical consumption or maximum power query for 24 hours, and the manual says a call that failed because of the caller's own mistake cannot be repeated either, so a rejected call counts. A call that timed out may have counted too. The client applies the same rule to the reactive energy query, to be safe.

- A query is "used for today" once it may have reached Datadis. Only `requestSent === false` on a `DatadisException` means it is still available.
- The package records each attempt in the shared cache store (`datadis-client.cache.store`), so a second worker, job or deploy gets `RepetitionWindowException` before anything is sent. Keep that store persistent and shared, and never flush it.
- Changing `datadis-client.ledger.key` (or `APP_KEY` when no key is set) forgets every recorded query.
- The guard keeps a query for 24 hours and 10 minutes: asking the same one at the same time the next day is refused. Schedule a repeat of the same query every second day.
- Switching from a record of your own (a table, say)? The guard does not know it: before any worker sends a guarded query with the new client, either wait 24 hours and 10 minutes, or seed the guard once with `LaravelDatadisClient::rememberAttempt(Endpoint::Consumption, $cups, $distributorCode, $from, $to, pointType: $pointType, at: $sentAt)` for every query sent in the last 25 hours, rejected and timed-out ones included. For maximum power and reactive energy give only the CUPS, the code and the months. If the old record may be incomplete, wait.
- Never wrap a data query in a retry: not `$tries`, not `retry()`, not `RetryingClient`. Let the next scheduled run try again tomorrow.
- Store the whole result, `raw` included: you cannot ask again for 24 hours.

## Pattern: one planning job, one job per month

A planning job decides which months are worth asking for, and one queued job per range makes the query. A worker killed in the middle then loses one month, not the whole backfill.

- The **planning job** only reads (the login and the supplies list). Reads are safe to retry, so it may be retried, and nothing is dispatched until the lookup has succeeded.
- Each **range job** makes one data query, which must never be retried by the queue. It takes the codes from the planner, so it does not list the supplies again.

```php
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;
use Lenorix\DatadisClient\Values\Cups;

class PlanSupplySync implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;               // only reads: safe to retry

    public array $backoff = [60, 300];

    public int $timeout = 900;           // a login and a supplies list, each up to 3 attempts of 120 s: see "Timeouts and queues"

    public function __construct(public string $cups, public int $months = 2) {}

    public function handle(DatadisClient $client): void // resolve here: the client is not serialisable
    {
        $supply = $client->findSupply(Cups::fromString($this->cups));
        if (! $supply?->isQueryable()) {
            return;
        }

        $now = new DateTimeImmutable;
        $current = Month::current($now);

        // One month each, one queued job per range: the last $months months (24 at most).
        // If a dispatch fails halfway and the job is retried, the ranges already queued are not queued again:
        // the range jobs are unique per supply and range.
        foreach (MonthPlanner::ranges($current->addMonths(-($this->months - 1)), $current, $now, supply: $supply) as [$from, $to]) {
            SyncSupplyRange::dispatch($supply->cups, $supply->distributorCode, $supply->pointType, $from->format(), $to->format());
        }
    }
}

class SyncSupplyRange implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;      // a data query must not be repeated by the queue

    public int $uniqueFor = 3600; // the same range is not queued twice within an hour

    public int $timeout = 600;  // a login (up to 3 attempts of 120 s) and the query (120 s): see "Timeouts and queues"

    public function __construct(
        public string $cups,    // as the supplies list gave it
        public string $distributorCode,
        public int $pointType,
        public string $from,
        public string $to,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->cups}:{$this->from}:{$this->to}";
    }

    public function handle(DatadisClient $client): void
    {
        try {
            $result = $client->getConsumptionData(
                Cups::fromString($this->cups),
                $this->distributorCode,
                $this->pointType,
                Month::fromString($this->from),
                Month::fromString($this->to),
            );
        } catch (NoDataException|RepetitionWindowException) {
            return; // nothing yet, or already asked today
        }

        if ($result->isEmptyBecauseOfErrors() || $result->isEmpty()) {
            return; // a distributor failed or it is not published: tomorrow
        }

        // persist $result->records and each ->raw
    }
}
```

Run the backfill once, and schedule only the months that can still change:

```php
PlanSupplySync::dispatch($cups, months: 24);   // once: the whole history

Schedule::job(new PlanSupplySync($cups, months: 2))
    ->dailyAt('04:00')
    ->when(fn () => intdiv(now()->timestamp, 86400) % 2 === 0);   // every second day: this month and the previous one
```

Every second day, not every day: the guard keeps a query for 24 hours and 10 minutes (a margin for clock differences with Datadis). A job that repeats the same query at the same time each day is refused locally every other run. Those refused runs are harmless, since the job catches `RepetitionWindowException`, but they fetch nothing.

Count the days (the `when()` above) instead of using `cron('0 4 */2 * *')`: that runs on the odd days of the month, so the 31st and the 1st of the next month are consecutive and the query is refused once there.

## Rules of thumb

### Timeouts and queues

Datadis is slow: a call can take tens of seconds, and each one is allowed up to `datadis-client.accounts.*.timeout` (120 seconds by default). A job's timeout must cover its worst case, not its usual one.

- **A read that fails over the network is retried** (`datadis-client.http.retries`, 2 retries by default), each attempt up to 120 seconds, with waits of at most 30 seconds between them: about 420 seconds in the worst case. A data query is never retried: 120 seconds at most.
- **The range job**: a login when the token is not cached, 420 seconds at worst, plus the query, 120: 540 seconds, so `$timeout = 600`. With the token cached it takes the query alone.
- **The planning job**: the login and the supplies list, 2 x 420 = 840 seconds at worst, so `$timeout = 900`.
- Set the job's `$timeout` (it takes precedence over the worker's `--timeout`, which is 60 seconds by default). It needs the `pcntl` PHP extension.
- Keep the connection's `retry_after` (90 seconds by default, in `config/queue.php`) greater than the longest job timeout: Laravel says a job's timeout "should always be less than its retry after value", or the job may be attempted again before it finishes, and the query is sent twice. Here that is 930 seconds or more, so give the Datadis jobs a connection of their own with that `retry_after` instead of raising it for every queue (`$this->onConnection('datadis')` in their constructors).
- Keep the worker's `--timeout` several seconds shorter than `retry_after`.
- The job timeout does not interrupt a blocking HTTP call: the package already gives Guzzle its own timeouts. Lower `datadis-client.accounts.*.timeout` if you want smaller job timeouts.

### Retries

- The package already retries the harmless reads (login, lists) after network errors and 502, 503 and 504 (`datadis-client.http.retries`).
- A job that only reads (the planning job: login and supplies list) may be retried by the queue too: `$tries = 3` with a `$backoff`.
- A job that issues a data query keeps `$tries = 1` and no `$backoff`.
- `RepetitionWindowException` is reported as a warning by default (`datadis-client.report_level`): do not turn it into an error.

### Data

- Plan ranges with `MonthPlanner::ranges(..., supply: $supply)`: a range before the contract start is refused locally, and a `401` on a guarded query is never sent again.
- The current month keeps changing for some days after it ends and has no data for the last two days. A run of trailing zeros is not real. Re-sync only months that can still change, once a day.
- Upsert readings by supply and real `start`, not by date and time: the autumn change repeats `03:00`.
- Keep energy as decimal strings or scaled integers, never floats.

### Accounts

- One account, one login: the token is cached in the shared store, so many workers do not log in again.
- Do not start the jobs of many accounts at the same instant without need.

## Sources

- The 24 hour rule: the Datadis API manual, sections 4.3 and 4.4 ("a control in the system does not allow repeating calls made in the last 24 hours"), in the API section of [datadis.es](https://datadis.es/private-api) (it asks for a Datadis login). The refusals the real service gives are in the client's [quirks and rules](https://github.com/lenorix/datadis-php-client/blob/main/docs/quirks-and-rules.md).
- Job timeouts and `retry_after`: Laravel, [Job Expiration](https://laravel.com/docs/13.x/queues#job-expiration), [Timeout](https://laravel.com/docs/13.x/queues#timeout) and [Worker Timeouts](https://laravel.com/docs/13.x/queues#worker-timeouts).
- The 24 hour guard and the retries of this package: its README, and the client's [design decisions](https://github.com/lenorix/datadis-php-client/blob/main/docs/design-decisions.md).
