---
name: datadis-development
description: Write code that reads Datadis data with lenorix/laravel-datadis-client - supplies, contracts, consumption, maximum power, reactive energy, tariff periods, authorizations and open data - and handles its results and errors. Use when adding or changing a controller, job, command, importer or calculation that calls Datadis.
---

# Datadis Development

## When to use this skill

Use it when code calls `DatadisClient` or `PublicApiClient`, or computes something from their results (consumption totals, tariff periods, billing). For what a field or a tariff means read `datadis-electricity-domain`; for jobs that sync data on a schedule read `datadis-sync`; for tests read `datadis-testing`.

## The steps of every data call

1. Get the client by injection (`DatadisClient $client`) or with `LaravelDatadisClient::account('name')`.
2. Find the supply with `findSupply(Cups::fromString($cups))`. It returns `null` when the account does not see it.
3. Check `$supply->isQueryable()`: the distributor code and point type that every data call needs come from the supplies list, never from you.
4. Call a `...Of($supply, ...)` method with whole months (`Month::of(2026, 7)`), within the last 24 months.
5. Read `$result->records`; check `isEmpty()` and `distributorErrors`.
6. Store the whole result, `raw` included: the same query cannot be asked again for 24 hours.

For supplies of someone who authorized the account use `$client->forHolder(Nif::fromString($nif))`, and never pass the account's own NIF as `authorizedNif`.

## Calls

```php
$supply = $client->findSupply(Cups::fromString($cups));              // ?Supply

if ($supply === null || ! $supply->isQueryable()) {
    return; // or fail: every call below needs a queryable supply
}

$contract = $client->getContractDetailOf($supply)->records[0] ?? null;
$hourly   = $client->getConsumptionDataOf($supply, Month::of(2026, 7));
$quarters = $client->getConsumptionDataOf($supply, $from, $to, MeasurementType::QuarterHourly);
$peaks    = $client->getMaxPowerOf($supply, $from, $to);              // one row per tariff period, kW
$reactive = $client->getReactiveDataOf($supply, $from, $to);          // API v2 only
$client->getSupplies(); $client->getDistributorsWithSupplies(); $client->getGroups();   // getGroups: API v2 only
$client->listAuthorization();                                         // read only
```

The consumption and maximum power calls are subject to the 24 hour rule, which the Datadis manual documents (sections 4.3 and 4.4). The client applies it to the reactive call too, to be safe. The others are free to repeat.

### Writes

These change data on Datadis. Run them only when the task asks for it, never retry them, and check the answer text they return.

```php
$client->newAuthorization($nif);                                  // a third party reads all your supplies
$client->newAuthorization($nif, $from, $to, Cups::fromString($cups));   // or some, for a period
$client->cancelAuthorization($nif);                               // take that access away
$client->partnerDeleteUser($nif);                                 // partner accounts only: unlink a user
$client->partnerUserList(); $client->partnerAgreementDate();      // partner reads
```

### Open data and the terminal

- Aggregated open data: `app(PublicApiClient::class)` or `LaravelDatadisClient::publicApi()`. `apiSearchAll()` pages for you.
- Artisan: `datadis:supplies`, `datadis:contract {cups}`, `datadis:consumption {cups} {YYYY-MM} [--to=] [--quarter-hourly]`, `datadis:authorizations`, `datadis:authorize {nif}`, `datadis:authorization:cancel {nif}`. All take `--account`. The reading ones (`supplies`, `contract`, `consumption`) also take `--holder`; the authorization commands act for the account itself and have none (`datadis:authorizations` takes `--owner`). A bad input fails before anything is sent. Exit code 0 is a success (an empty answer, or a distributor error beside real data, only warns); 1 is a bad input, a Datadis error, or a distributor failure with no data. A script can rely on it.

## Reading the results

Every list call returns an `ApiResult`: `records`, `isEmpty()`, `distributorErrors`, `isEmptyBecauseOfErrors()` and `skippedRows`. Each record keeps the untouched row in `raw`.

- **Empty is not zero.** `isEmpty()` means nothing is published yet, never "zero consumption".
- **Numbers are decimal strings.** Add them with `Brick\Math\BigDecimal`, never with floats.
- **Dates** are `DateTimeImmutable` in the client's time zone: `Europe/Madrid` by default, `Atlantic/Canary` for the Canary Islands (`timezone` in the account config).
- **Hours** are labelled `01:00` to `24:00` and mark the end of the hour. The last Sunday of October has two `03:00` rows and the last Sunday of March has none. Use each reading's `start`, `end`, `index` and `hourOfDay`; never expect 24 rows or key by date and time.
- **Extra rows.** An extra `00:00` row has been reported from some distributors: skip it when `$reading->hasValidTime()` is false before adding energy up.
- **Tariff periods.** `$contract->tariff()` is an `AccessTariff` (T20TD, T30TD, T61TD...) or `null`. `->schedule(Territory::fromPostalCode($supply->postalCode) ?? Territory::Peninsula)->periodFor($day, $hour)` gives the period of an hour.
- **CUPS exactly as listed.** Datadis answers "not authorized" for a lowercase or truncated CUPS; the `...Of($supply)` methods send it right.

## Errors

Every failure is a `Lenorix\DatadisClient\Exceptions\DatadisException` with `requestSent`, `httpStatus` and `endpoint`. Its message is already stripped of CUPS, NIF and tokens, so it is safe to log.

| Exception | Meaning | Do |
|---|---|---|
| `NoDataException` | 404, 204 or an empty body | treat it as an empty result |
| `RepetitionWindowException` | same query in the last 24 hours | wait; do not retry |
| `AuthorizationException` | not authorized, authorization expired or stale codes | check the authorization, reload the supply |
| `AuthenticationException` | bad credentials, or the token was rejected | fix the credentials; if `requestSent`, the query counts |
| `RequestRejectedException` | Datadis refused the parameters | fix them; never resend as is |
| `InvalidRequestException` | refused before sending | fix; nothing was sent |
| `ServiceUnavailableException`, `TransportException` | Datadis or the network failed | later; a data query that may have arrived counts as used today |

`Cups`, `Nif` and `Month` throw `InvalidArgumentException` on malformed input: check with `Cups::isValid()` and `Nif::isValid()` when it comes from a user.

## Do not

- Do not call the same consumption, maximum power or reactive query twice in a day, from a retry or from a test.
- Do not log, dump or record credentials, tokens or Datadis requests.
- Do not assume a supply belongs to the account: handle `findSupply()` returning `null`.
- Do not add floats, and do not key readings by date and time.

## Sources

- Endpoints, parameters and answers: the Datadis API manual, in the API section of [datadis.es](https://datadis.es/private-api) (it asks for a Datadis login).
- What the real service does, with the evidence of each point (units, refusals, hour labels, error answers): the client's [API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md) and [quirks and rules](https://github.com/lenorix/datadis-php-client/blob/main/docs/quirks-and-rules.md).
- The meaning of a field or a tariff: the `datadis-electricity-domain` skill, with its official sources.
- Every call, result and exception of the client: the [lenorix/datadis-client README](https://github.com/lenorix/datadis-php-client#readme).
