## Laravel Datadis Client

Reads electricity data from Datadis, where Spanish distributors publish supply data: supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy, authorizations and open data. It wraps `lenorix/datadis-client` and connects it to Laravel's cache and configuration.

### Rules that must not be broken

- **The 24 hour rule.** Datadis refuses an identical consumption, maximum power or reactive query for 24 hours and counts the refused ones. Never loop over such a query and never add a retry of your own around it; a repeat throws `RepetitionWindowException` before anything is sent.
- **Never expose credentials.** The Datadis password and the token must not reach logs, events or request recorders. Keep `datadis-client.http.stack` unset (plain Guzzle) outside tests.
- **Never put the client in a queued job property.** It holds a password and cannot be serialised: type-hint it in `handle()`.
- **Writes change data on Datadis.** `newAuthorization()`, `cancelAuthorization()` and `partnerDeleteUser()` are never retried and return Datadis's answer text, so check it. Run them only when the task asks for it.
- **No test may reach the real Datadis.** Fake it (see the `datadis-testing` skill).

### Using the client

- Inject `Lenorix\DatadisClient\DatadisClient`, or use the `Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient` facade. `LaravelDatadisClient::account('name')` picks another account, `->forHolder(Nif)` reads a third party's supplies, and `app(Lenorix\DatadisClient\PublicApiClient::class)` reads the open data.
- Start from the supply: `findSupply(Cups)` returns it, or `null`. Check `isQueryable()` before the `...Of($supply, ...)` calls.
- Method and field names are Datadis's own (`getConsumptionData()`, `consumptionKWh`). Energy values are decimal strings, never floats.
- Datadis hours end at `24:00` and a daylight saving day has 23 or 25 rows: use each reading's `start`, never date plus time.

@verbatim
<code-snippet name="Read a month of consumption" lang="php">
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

public function handle(DatadisClient $client): void
{
    $supply = $client->findSupply(Cups::fromString('ES0000000000000000AA0A'));

    if ($supply?->isQueryable()) {
        $result = $client->getConsumptionDataOf($supply, Month::of(2026, 7));
    }
}
</code-snippet>
@endverbatim

- Artisan: `datadis:supplies`, `datadis:contract`, `datadis:consumption` (counts for the 24 hour rule), `datadis:authorizations`, `datadis:authorize`, `datadis:authorization:cancel`. All take `--account` and `--holder`.

### Configuration

- Credentials: `config/services.php`, key `datadis` (`DATADIS_USERNAME`, `DATADIS_PASSWORD`). Other accounts and settings: `config/datadis-client.php`. Never hard-code them.
- `datadis-client.cache.store`: a store with an atomic `add()` (Redis, Memcached, database). `file` only coordinates one server and `array` protects nothing.
- `datadis-client.ledger.key` (`DATADIS_LEDGER_KEY`): set it; otherwise `APP_KEY` is used and rotating it makes the guard forget the last 24 hours.
- Harmless reads are retried by the package after network errors and 502, 503 and 504 (`datadis-client.http.retries`); data queries and writes never are.

### More detail

- `datadis-development`: calls, results, errors and tariff periods.
- `datadis-sync`: scheduled jobs and backfills.
- `datadis-testing`: faking Datadis with `Http::fake()`.
