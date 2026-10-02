---
name: datadis-sync
description: Build scheduled jobs, commands and backfills that sync Datadis consumption, maximum power or reactive data in Laravel without wasting the 24 hour query rule. Use when writing queued jobs, schedulers or importers on top of lenorix/laravel-datadis-client.
---

# Datadis Sync

## When to use this skill

Use it for anything that fetches Datadis data on a schedule or in bulk: nightly syncs, historical backfills (up to 24 months), per-customer importers.

## The 24 hour rule

Datadis refuses an identical consumption, maximum power or reactive query for 24 hours **and counts every call it receives, including rejected ones and timeouts**. So:

- A query is "used for today" once it may have reached Datadis. Only `requestSent === false` on a `DatadisException` means it is still available.
- The package records each attempt in the cache store shared by all workers (`datadis-client.cache.store`, atomic through `Cache::add()`), so a second worker, job or deploy gets `RepetitionWindowException` before anything is sent. Keep the store persistent and shared; do not flush it. Changing `datadis-client.ledger.key` forgets every recorded query.
- Never wrap a data query in a generic retry (`$tries`, `retry()`, `RetryingClient`): the client itself never repeats one. Let the next scheduled run try again tomorrow.
- Store the whole result, `raw` included: you cannot ask again for 24 hours.

## Backfill pattern

`MonthPlanner` turns a range into the requests worth making (only months Datadis serves and, given the supply, only months of its contract). One month per request is the default on purpose.

One job plans the months, and one job per range makes the query. A worker killed in the middle then loses one month, not the whole backfill, and each job stays inside its timeout.

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

    public function __construct(public string $cups) {}

    public function handle(DatadisClient $client): void // resolve here: the client is not serialisable
    {
        $supply = $client->findSupply(Cups::fromString($this->cups));
        if (! $supply?->isQueryable()) {
            return;
        }

        $now = new DateTimeImmutable;
        $current = Month::current($now);

        // At most 24 ranges, one month each: one queued job per range.
        foreach (MonthPlanner::ranges($current->addMonths(-23), $current, $now, supply: $supply) as [$from, $to]) {
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

## Rules of thumb

- The package already retries the harmless reads (login, lists) after network failures and 502, 503 and 504 (`datadis-client.http.retries`); never add a retry of your own around data queries.
- Keep `tries = 1` (and no `backoff`) on jobs that issue data queries; supplies, contract detail, distributors, groups and authorization lists are safe to retry.
- Datadis is slow (tens of seconds a query): a job's timeout must exceed what it does in sequence. Count the login, the supplies list and each query, every one up to `datadis-client.accounts.*.timeout` (default 120 s): 24 months in one job could need almost an hour, so make one job per range instead. Keep the queue's `retry_after` and the worker's `--timeout` above the job timeout, or the job is released and run twice.
- The current month keeps changing for some days after it ends and has no data for the last ~2 days; do not treat a run of trailing zeros as real. Re-sync only months that can still change, and only once a day.
- Upsert readings by supply and real `start` (not date + time: the autumn change repeats `03:00`), and keep the energy as decimal strings or integer-scaled values, never floats.
- One account, one login: the token is cached in the shared store, so many workers do not log in repeatedly. Do not run many accounts' jobs at the same instant against one rate-sensitive store without need.
- Plan ranges with `MonthPlanner::ranges(..., supply: $supply)`: a range before the contract start is refused locally, and a `401` on a guarded query is never sent again (the query may already count).
- By default the package reports `RepetitionWindowException` as a warning through Laravel's exception handler (`datadis-client.report_level`); do not turn it into an error.
