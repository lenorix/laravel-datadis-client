## Laravel Datadis Client

This package integrates `lenorix/datadis-client` with Laravel to read electricity data from Datadis, the platform where Spanish distributors publish supply data (supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy). The client is plain PHP; this package binds it to Laravel's `Http`, cache and configuration.

### Conventions

- Get the client by injecting `Lenorix\DatadisClient\DatadisClient` or with the `Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient` facade (default account). Use `LaravelDatadisClient::account('name')` for another account and `->forHolder(Nif)` for a third party who authorized the account.
- Credentials live in `config/services.php` under `datadis` (`DATADIS_USERNAME`, `DATADIS_PASSWORD`); extra accounts and other settings in `config/datadis-client.php`. Never hard-code them, log them or put the client in a queued job property: it cannot be serialised, so resolve it in `handle()`.
- Method and field names are Datadis's own (`getConsumptionData()`, `consumptionKWh`, `contractedPowerkW`).
- Energy and power values are decimal strings, never floats: add them with `Brick\Math\BigDecimal`.
- The client also changes data on Datadis with `newAuthorization()`, `cancelAuthorization()` and `partnerDeleteUser()`: call them only when the user asks for it.
- Public open data (aggregated by region, tariff, sector) is `app(Lenorix\DatadisClient\PublicApiClient::class)` or `LaravelDatadisClient::publicApi()`.
- Datadis refuses an identical consumption, maximum power or reactive query for 24 hours, and counts the refused ones. Never loop or retry such a query. The package guards this with a cache shared by all workers; a repeat throws `RepetitionWindowException` before anything is sent.
- Always start from the supplies list: `findSupply(Cups)` gives the CUPS, distributor code and point type every data call needs. Use the `...Of($supply, Month)` methods.
- Datadis hours end at `24:00` and a daylight saving day has 23 or 25 rows: never key readings by date and time, use each reading's `start`.

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

- Security: unset, `datadis-client.http.stack` is `guzzle` (plain Guzzle) so Laravel's HTTP events, middleware and recorders never see the login password and the token; the test environment uses `laravel` so `Http::fake()` works. Do not switch production to `laravel`, and never log or record Datadis requests.
- Test with `Http::fake()` (and `Http::preventStrayRequests()`): no test should reach the real Datadis. The `datadis-testing` skill has the endpoints and payloads.
- Use a cache store with an atomic `add()` for `datadis-client.cache.store`: Redis, Memcached or database for several servers; `file` only coordinates processes on one host; `array` protects nothing across workers.
