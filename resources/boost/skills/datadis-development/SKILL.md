---
name: datadis-development
description: Read Datadis electricity data (supplies, contracts, hourly/quarter-hourly consumption, maximum power, reactive energy, authorizations, public open data) with lenorix/laravel-datadis-client. Use when writing code that calls Datadis, handles its results or its errors.
---

# Datadis Development

## When to use this skill

Use it when adding or changing code that reads from Datadis through `DatadisClient`: controllers, jobs, commands, importers, tariff or billing calculations on the readings.

## How Datadis works

- You log in with your own account. For supplies of someone who authorized you, use `$client->forHolder(Nif::fromString('00000000T'))`; never pass your own NIF as `authorizedNif`.
- A supply is identified by its CUPS, but every data call also needs its **distributor code** and **point type**, which only the supplies list has. Always call `findSupply()` or `getSupplies()` first and check `$supply->isQueryable()`.
- Data is requested by whole months (`Month::of(2026, 7)`), within the last 24 months. The current month has data up to about two days ago.
- Send the CUPS exactly as the supplies list returns it (not lowercase, not truncated): otherwise Datadis answers "not authorized". Prefer the `...Of($supply, ...)` methods.

## Calls

```php
$supply   = $client->findSupply(Cups::fromString($cups));            // ?Supply
$contract = $client->getContractDetailOf($supply)->records[0] ?? null;
$hourly   = $client->getConsumptionDataOf($supply, Month::of(2026, 7));
$quarters = $client->getConsumptionDataOf($supply, $from, $to, MeasurementType::QuarterHourly);
$peaks    = $client->getMaxPowerOf($supply, $from, $to);              // one row per tariff period, kW
$reactive = $client->getReactiveDataOf($supply, $from, $to);          // API v2 only
$client->getDistributorsWithSupplies();
$client->listAuthorization(); $client->newAuthorization($nif); $client->cancelAuthorization($nif);
$client->getGroups();                                                 // API v2 only
```

Public open data (aggregated by region, tariff, sector) uses `PublicApiClient` with the same account; `apiSearchAll()` pages for you.

## Results

Every list method returns an `ApiResult`: `records`, `isEmpty()` (not yet published, never "zero consumption"), `distributorErrors`, `isEmptyBecauseOfErrors()`, `skippedRows`. Each record keeps the untouched row in `raw`.

- Numbers are decimal strings; add them with `Brick\Math\BigDecimal`, never floats.
- Dates are `DateTimeImmutable` in the client's time zone (Europe/Madrid by default; set `timezone` in the account config, `Atlantic/Canary` for the Canary Islands).
- Hours are labelled `01:00` to `24:00` and mark the **end** of the hour. The last Sunday of October has two `03:00` rows, the last Sunday of March has none. Use each reading's `start`, `end`, `index` and `hourOfDay`; do not expect 24 rows or key by date + time.
- Some distributors send an extra `00:00` row: skip it when `$reading->hasValidTime()` is false before adding energy up.
- `$contract->tariff()` gives an `AccessTariff` (T20TD, T30TD, T61TD...) or null; `->schedule(Territory::fromPostalCode($supply->postalCode) ?? Territory::Peninsula)->periodFor($day, $hour)` gives the tariff period of an hour.

## Errors

All failures are `Lenorix\DatadisClient\Exceptions\DatadisException` subclasses with `requestSent`, `httpStatus`, `endpoint` and a `detail` already stripped of CUPS, NIF and tokens, so their messages are safe to log.

| Exception | Meaning | Do |
|---|---|---|
| `NoDataException` | 404/204/empty | treat as empty result |
| `RepetitionWindowException` | same query in the last 24 h | wait; do not retry |
| `AuthorizationException` | not authorized, expired authorization or stale codes | check the authorization, reload the supply |
| `AuthenticationException` | bad credentials, or token rejected | fix credentials; if `requestSent`, the query counts |
| `RequestRejectedException` | parameters refused | fix, never resend as is |
| `InvalidRequestException` | refused before sending | fix; nothing was sent |
| `ServiceUnavailableException`, `TransportException` | Datadis or network failed | later; a data query that may have arrived counts as used today |

`Cups`, `Nif` and `Month` throw `InvalidArgumentException` on malformed input: check with `Cups::isValid()` / `Nif::isValid()` when it comes from users.

## Do not

- Do not log or dump credentials; records hide personal data from `dump()` but their properties are readable.
- Do not call the same consumption/power/reactive query twice in a day, even from a "retry" or a test against the real service.
- Do not assume a supply is yours: handle `findSupply()` returning null.
