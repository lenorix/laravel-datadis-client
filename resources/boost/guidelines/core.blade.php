## Laravel Datadis Client

Reads electricity data from Datadis, where Spanish distributors publish supply data: supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy, authorizations and open data. It wraps `lenorix/datadis-client` and connects it to Laravel's cache and configuration.

### Rules that must not be broken

- **The 24 hour rule.** Datadis refuses an identical consumption or maximum power query for 24 hours and counts the refused ones (its manual documents the rule for those two; the client applies it to reactive energy too, to be safe). Never loop over such a query and never add a retry of your own around it. When you move from a record of your own to this package, seed the guard with `rememberConsumption()`, `rememberMaxPower()` and `rememberReactive()` or wait 24 hours and 10 minutes before sending, and pause the workers while you import; a repeat throws `RepetitionWindowException` before anything is sent.
- **A daily refresh uses `getLatestConsumptionDataOf()` and `getLatestMaxPowerOf()`,** never the same range every day: they alternate the range from one day to the next, so nothing repeats inside the window (one exception, below). One run a day. The exception: in the month the contract starts the range is that month every day, so a run within 24 hours and 10 minutes of the day before is refused; catch `RepetitionWindowException` (that month refreshes every second day). Reactive energy has no such method: ask it for closed months only.
- **Never expose credentials.** The Datadis password and the token must not reach logs, events or request recorders. Keep `datadis-client.http.stack` unset (plain Guzzle) outside tests, and do not read the token cache key or dispatch cache events for it.
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

- Artisan: `datadis:supplies`, `datadis:contract`, `datadis:consumption` (counts for the 24 hour rule), `datadis:authorizations`, `datadis:authorize`, `datadis:authorization:cancel`. All take `--account`; the reading ones also take `--holder`, and the authorization ones act for the account itself.

### Configuration

- Credentials: `config/services.php`, key `datadis` (`DATADIS_USERNAME`, `DATADIS_PASSWORD`). Other accounts and settings: `config/datadis-client.php`. Never hard-code them.
- The cache: the token and the guard use Laravel's default cache store (`CACHE_STORE`), with no setting of the package. Use `redis` or `database`: `file` only coordinates one server, `array` only protects within one process, and `null` is refused.
- `datadis-client.ledger.key` (`DATADIS_LEDGER_KEY`): optional, at least 16 bytes. Without it the secret is derived from `APP_KEY`, and rotating `APP_KEY` makes the guard forget the last 24 hours, so set it if you rotate `APP_KEY`.
- Harmless reads are retried by the package after network errors and 502, 503 and 504 (`datadis-client.http.retries`); data queries and writes never are.

### More detail

- `datadis-development`: calls, results and errors.
- `datadis-electricity-domain`: CUPS, access tariffs, periods P1 to P6, power, reactive energy and territories.
- `datadis-sync`: scheduled jobs and backfills.
- `datadis-testing`: faking Datadis with `Http::fake()`.

### Official sources

When you explain a rule, a tariff or a field to the user, cite where it comes from. The `datadis-electricity-domain` skill gives the exact article of each rule.

- Datadis API manual: the API section of [datadis.es](https://datadis.es/private-api) (it asks for a Datadis login).
- Tariffs, periods, holidays and reactive energy: [Circular CNMC 3/2020](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066).
- National holidays for the periods: [Real Decreto 2001/1983](https://www.boe.es/buscar/act.php?id=BOE-A-1983-20906), art. 45.1.
- Measurement point types: [Real Decreto 1110/2007](https://www.boe.es/buscar/act.php?id=BOE-A-2007-16478#a7), art. 7.
- The CUPS: [CNMC, "El CUPS"](https://www.cnmc.es/sites/default/files/editor_contenidos/Energia/Consumidores/3.1.%20El%20CUPS.pdf).
- How the real service behaves, with evidence: the [client's documentation](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md).
