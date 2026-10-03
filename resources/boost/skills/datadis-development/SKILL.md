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
3. Check `$supply->isQueryable()`: the distributor code and point type that the data calls need come from the supplies list, never from you. Only consumption takes the point type: contract detail, maximum power and reactive energy need the CUPS and the distributor code, and every `...Of()` call refuses an unusable supply with an `InvalidRequestException` before sending anything.
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
$quarters = $client->getConsumptionDataOf($supply, $from, $to, MeasurementType::QuarterHourly);   // $from, $to: Month values
$peaks    = $client->getMaxPowerOf($supply, $from, $to);              // one row per tariff period, kW
$today    = $client->getLatestConsumptionDataOf($supply);            // the daily refresh: its range changes from one day to the next
$peak     = $client->getLatestMaxPowerOf($supply);                    // the same, for maximum power
$reactive = $client->getReactiveDataOf($supply, $from, $to);          // API v2 only
$client->getSupplies(); $client->getDistributorsWithSupplies(); $client->getGroups();   // getGroups: API v2 only
$client->listAuthorization();                                         // read only
$client->checkLogin();                                                // logs in or takes the cached token; when it lasts, without reading data
$client->assertServedRange($from, $to);                              // refuses a range Datadis would refuse, before any login
$until = $client->consumptionDataOfBlockedUntil($supply, $from);      // ?DateTimeImmutable: until when the ledger refuses it, nothing sent or claimed
```

`getLatestConsumptionDataOf()` and `getLatestMaxPowerOf()` are for a job that runs every day: the range alternates between the current month and the previous plus the current month, so today's query is never yesterday's. Run it once a day. In the month the contract starts the range is that month every day (there is no previous month to alternate with), so a run within 24 hours and 10 minutes of the day before throws `RepetitionWindowException`: catch it. It throws `NothingToRefreshException` when the contract has nothing to refresh this month (it ended before it, or starts after it); catch that type, not `InvalidRequestException`, which would also swallow a bad argument. Reactive energy has no such method: ask it for closed months.

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

### Invoice periods

`BillingCycle::monthlyFrom(15)->lastEndedPeriod(now())` gives the last closed period of a cycle that starts on the 15th, and `BillingPeriod::between($firstDay, $lastDay)` takes the dates of an invoice, which are the reliable source: Datadis does not publish the billing day, the retailer sets it and may move it. A period gives the months to ask for (`months()`), the readings that fall in it (`readingsOf()`), their exact total (`totalKWh()`) and whether the readings reach its end (`isCoveredBy()`). Dates are taken as they are on the Madrid calendar. This comes from the client's own documentation, not from a regulation: do not present the cycle as a rule. A period over the previous month and the current one is the same guarded query as the daily refresh asks on every other day: whichever runs second throws `RepetitionWindowException`. If a daily refresh runs, compute the period from the readings it already returned and stored, or catch the exception.

### Open data and the terminal

- Aggregated open data: `app(PublicApiClient::class)` or `LaravelDatadisClient::publicApi()`. `apiSearchAll()` pages for you.
- Artisan: `datadis:supplies`, `datadis:contract {cups}`, `datadis:consumption {cups} {YYYY-MM} [--to=] [--quarter-hourly]`, `datadis:authorizations`, `datadis:authorize {nif}`, `datadis:authorization:cancel {nif}`. All take `--account`. The reading ones (`supplies`, `contract`, `consumption`) also take `--holder`; the authorization commands act for the account itself and have none (`datadis:authorizations` takes `--owner`). A bad input fails before anything is sent. Exit code 0 is a success (an empty answer, or a distributor error beside real data, only warns); 1 is a bad input, a Datadis error, or a distributor failure with no data. A script can rely on it.

## Reading the results

Every list call returns an `ApiResult`: `records`, `isEmpty()`, `distributorErrors`, `isEmptyBecauseOfErrors()` and `skippedRows`. Each record keeps the untouched row in `raw`.

- **The months asked for.** A consumption, maximum power or reactive result carries `startDate` and `endDate`: the range that was asked, which `getLatest...Of()` chose, also when a month came back empty.
- **Daily answers hold two months** every other day: split them by month before adding them up.
- **Empty is not zero.** `isEmpty()` means nothing is published yet, never "zero consumption".
- **Numbers are decimal strings,** and `raw` holds them as text too (`"0.301"`, not `0.301`: every digit Datadis sends is kept). Add them with `Brick\Math\BigDecimal`, never with floats.
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
| `OutOfServedRangeException` (`month`) | a month Datadis does not serve: in the future, or more than 24 months back | ask for a month inside the window |
| `OutOfContractRangeException` (`contractStart`, `contractEnd`) | a range before the contract started or after it ended | clip the range to the contract |
| `NothingToRefreshException` | `getLatest...Of()` on a contract with nothing to refresh this month | skip the run |

The last three are `InvalidRequestException`s: catch the specific type when you mean it, and let the parent report a real mistake.
| `ServiceUnavailableException`, `TransportException` | Datadis or the network failed | later; a data query that may have arrived counts as used today |
| `PageLimitReachedException` | `apiSearchAll()` or `apiSearchAutoAll()` stopped at `maxPages` with a full last page, after the last record | more records may remain: it carries `nextPage` and `skippedRows` |

After a `401` the client logs in again and repeats only the calls that are safe to repeat: a guarded query, `newAuthorization()`, `cancelAuthorization()` and `partnerDeleteUser()` are not sent again, and fail with an `AuthenticationException` whose `requestSent` is `true`. A `RepetitionWindowException` from the ledger carries `availableAt` (when the query is allowed again) and `lastAttemptAt`.

`Cups`, `Nif` and `Month` throw `InvalidArgumentException` on malformed input: check with `Cups::isValid()` and `Nif::isValid()` when it comes from a user.

## Do not

- Do not call the same consumption, maximum power or reactive query twice within 24 hours and 10 minutes, from a retry or from a test.
- Do not ask the same range every day in a scheduled job: use `getLatestConsumptionDataOf()` and `getLatestMaxPowerOf()`.
- Do not log, dump or record credentials, tokens or Datadis requests.
- Do not assume a supply belongs to the account: handle `findSupply()` returning `null`.
- Do not add floats, and do not key readings by date and time.

## Sources

- Endpoints, parameters and answers: the Datadis API manual, in the API section of [datadis.es](https://datadis.es/private-api) (it asks for a Datadis login).
- What the real service does, with the evidence of each point (units, refusals, hour labels, error answers): the client's [API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md) and [quirks and rules](https://github.com/lenorix/datadis-php-client/blob/main/docs/quirks-and-rules.md).
- The meaning of a field or a tariff: the `datadis-electricity-domain` skill, with its official sources.
- Every call, result and exception of the client: the [lenorix/datadis-client README](https://github.com/lenorix/datadis-php-client#readme).
