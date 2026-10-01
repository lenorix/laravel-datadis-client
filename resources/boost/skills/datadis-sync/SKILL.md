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

class SyncSupply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a data query must not be repeated by the queue

    public function __construct(public string $cups) {}

    public function handle(DatadisClient $client): void // resolve here: the client is not serialisable
    {
        $supply = $client->findSupply(Cups::fromString($this->cups));
        if (! $supply?->isQueryable()) {
            return;
        }

        $now = new DateTimeImmutable;
        $current = Month::current($now);

        foreach (MonthPlanner::ranges($current->addMonths(-23), $current, $now, supply: $supply) as [$from, $to]) {
            try {
                $result = $client->getConsumptionDataOf($supply, $from, $to);
            } catch (NoDataException|RepetitionWindowException) {
                continue; // nothing yet, or already asked today
            }

            if ($result->isEmptyBecauseOfErrors() || $result->isEmpty()) {
                continue; // a distributor failed or it is not published: tomorrow
            }

            // persist $result->records and each ->raw
        }
    }
}
```

## Rules of thumb

- Keep `tries = 1` (and no `backoff`) on jobs that issue data queries; supplies, contract detail, distributors, groups and authorization lists are safe to retry.
- Datadis is slow (tens of seconds): job timeout above `datadis-client.accounts.*.timeout` (default 120 s).
- The current month keeps changing for some days after it ends and has no data for the last ~2 days; do not treat a run of trailing zeros as real. Re-sync only months that can still change, and only once a day.
- Upsert readings by supply and real `start` (not date + time: the autumn change repeats `03:00`), and keep the energy as decimal strings or integer-scaled values, never floats.
- One account, one login: the token is cached in the shared store, so many workers do not log in repeatedly. Do not run many accounts' jobs at the same instant against one rate-sensitive store without need.
- Plan ranges with `MonthPlanner::ranges(..., supply: $supply)`: a range before the contract start is refused locally, and a `401` on a guarded query is never sent again (the query may already count).
- The package already reports `RepetitionWindowException` as a warning through Laravel's exception handler; do not turn it into an error.
