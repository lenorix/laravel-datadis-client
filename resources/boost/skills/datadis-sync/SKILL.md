---
name: datadis-sync
description: Build scheduled jobs, commands and backfills that sync Datadis consumption, maximum power or reactive data in Laravel without wasting the 24 hour query rule - one job per month, sized timeouts, no retries on data queries. Use when writing queued jobs, schedulers or importers on top of lenorix/laravel-datadis-client.
---

# Datadis Sync

## When to use this skill

Use it for anything that fetches Datadis data on a schedule or in bulk: a nightly sync, a historical backfill of up to 24 months, a per-customer importer. For single calls and results read `datadis-development`.

## The 24 hour rule

Datadis refuses an identical consumption, maximum power or reactive query for 24 hours and counts every call it receives, including rejected ones and timeouts.

- A query is "used for today" once it may have reached Datadis. Only `requestSent === false` on a `DatadisException` means it is still available.
- The package records each attempt in the shared cache store (`datadis-client.cache.store`), so a second worker, job or deploy gets `RepetitionWindowException` before anything is sent. Keep that store persistent and shared, and never flush it.
- Changing `datadis-client.ledger.key` (or `APP_KEY` when no key is set) forgets every recorded query.
- The guard keeps a query for 24 hours and 10 minutes: asking the same one at the same time the next day is refused. Schedule a repeat of the same query every second day.
- Never wrap a data query in a retry: not `$tries`, not `retry()`, not `RetryingClient`. Let the next scheduled run try again tomorrow.
- Store the whole result, `raw` included: you cannot ask again for 24 hours.

## Pattern: one planning job, one job per month

A planning job decides which months are worth asking for, and one queued job per range makes the query. A worker killed in the middle then loses one month, not the whole backfill, and each job stays inside its timeout.

```php
use DateTimeImmutable;
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

    public int $tries = 1;

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
        foreach (MonthPlanner::ranges($current->addMonths(-($this->months - 1)), $current, $now, supply: $supply) as [$from, $to]) {
            SyncSupplyRange::dispatch($this->cups, $from->format(), $to->format());
        }
    }
}

class SyncSupplyRange implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;     // a data query must not be repeated by the queue

    public int $timeout = 300; // more than datadis-client.accounts.*.timeout (120 s): the login and the supplies list come first

    public function __construct(public string $cups, public string $from, public string $to) {}

    public function handle(DatadisClient $client): void
    {
        $supply = $client->findSupply(Cups::fromString($this->cups));
        if (! $supply?->isQueryable()) {
            return;
        }

        try {
            $result = $client->getConsumptionDataOf($supply, Month::fromString($this->from), Month::fromString($this->to));
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

Schedule::job(new PlanSupplySync($cups, months: 2))->cron('0 4 */2 * *');   // every second day: this month and the previous one
```

Every second day, not every day: the guard keeps a query for 24 hours and 10 minutes (a margin for clock differences with Datadis). A job that repeats the same query at the same time each day is refused locally every other run. Those refused runs are harmless, since the job catches `RepetitionWindowException`, but they fetch nothing.

## Rules of thumb

### Timeouts and queues

- Datadis is slow: a query can take tens of seconds, and each call is allowed up to `datadis-client.accounts.*.timeout` (default 120 s).
- A job's timeout must cover what it does in sequence: the login, the supplies list and each query. Twenty-four months in one job could need almost an hour, so make one job per range.
- Keep the queue's `retry_after` above the job timeout, and the worker's `--timeout` at or above it. Laravel's default `retry_after` is 90 seconds, so for `$timeout = 300` set `retry_after` to 330 or more in `config/queue.php`, and run `php artisan queue:work --timeout=300`. Otherwise the job is released while it still runs, and the query is sent twice.

### Retries

- The package already retries the harmless reads (login, lists) after network errors and 502, 503 and 504 (`datadis-client.http.retries`).
- Keep `$tries = 1` and no `backoff` on jobs that issue data queries. Supplies, contract detail, distributors, groups and authorization lists are safe to retry.
- `RepetitionWindowException` is reported as a warning by default (`datadis-client.report_level`): do not turn it into an error.

### Data

- Plan ranges with `MonthPlanner::ranges(..., supply: $supply)`: a range before the contract start is refused locally, and a `401` on a guarded query is never sent again.
- The current month keeps changing for some days after it ends and has no data for the last two days. A run of trailing zeros is not real. Re-sync only months that can still change, once a day.
- Upsert readings by supply and real `start`, not by date and time: the autumn change repeats `03:00`.
- Keep energy as decimal strings or scaled integers, never floats.

### Accounts

- One account, one login: the token is cached in the shared store, so many workers do not log in again.
- Do not start the jobs of many accounts at the same instant without need.
