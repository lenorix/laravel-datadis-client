---
name: datadis-electricity-domain
description: Spanish electricity domain knowledge for reading Datadis data - CUPS, access tariffs (2.0TD to 6.4TD), tariff periods P1 to P6 and their calendars, contracted and maximum power, point types, quarter-hourly data, reactive energy, self-consumption, territories and time zones, and the authorization model. Use when interpreting Datadis fields or computing anything from consumption, power or tariffs.
---

# Datadis Electricity Domain

## When to use this skill

Use it when you must understand what a Datadis field means, or compute something that depends on the Spanish electricity rules: consumption per tariff period, contracted against maximum power, a comparison of tariffs, a check of reactive energy. For the calls themselves read `datadis-development`.

## Units and shapes

- **Energy** is in kWh (`consumptionKWh`, `surplusEnergyKWh`, `generationEnergyKWh`). **Power** is in kW (`contractedPowerkW`, maximum power). **Reactive energy** is in kvarh.
- Values arrive as decimal strings and keep every digit Datadis sends. Add them with `Brick\Math\BigDecimal`, never with floats.
- A reading is **hourly** (24 a day, in a daylight saving change 23 or 25) or **quarter-hourly** (96 a day). Each label marks the **end** of the interval, so `24:00` is the last hour.
- `obtainMethod` says where an hourly value comes from: `isReal()` is a meter reading, `isEstimated()` is an estimate. Keep that when you total, or tell the user.

## The supply: CUPS and its codes

- A **CUPS** identifies a supply point: `ES`, 16 digits, 2 control letters, and sometimes a digit and a letter more (for example `ES0000000000000000AA0A`). Datadis wants it exactly as listed.
- Every data call also needs the **distributor code** (an opaque short text such as `2`) and the **point type**. Both come from the supplies list.
- The **point type** (`pointType`) is a whole number from 1 to 5 (RD 1110/2007). Types 1 to 3 are large consumers with quarter-hourly metering and a maximeter. Types 4 and 5 are smaller supplies; most homes are type 5.
- Quarter-hourly data exists for types 1 and 2 (and 3 for one distributor). For the others Datadis answers with an empty list: that is not an error and not zero consumption.

## Access tariffs

The access tariff (`peaje de acceso`, Circular CNMC 3/2020) fixes how many periods a supply has and how its hours are priced.

| Tariff | Who | Energy periods | Power periods |
|---|---|---|---|
| 2.0TD | low voltage, up to 15 kW | 3 (P1 to P3) | 2 |
| 3.0TD | low voltage, above 15 kW | 6 | 6 |
| 6.1TD | high voltage from 1 kV up to 30 kV | 6 | 6 |
| 6.2TD | from 30 kV up to 72.5 kV | 6 | 6 |
| 6.3TD | from 72.5 kV up to 145 kV | 6 | 6 |
| 6.4TD | 145 kV and above | 6 | 6 |

- `$contract->tariff()` returns an `AccessTariff` or `null` when it is unsure. Datadis sends `accessFare` as free text (`BAJA TENSION y POTENCIA <= 15 kW`), so the client reads its shape and checks it against the contracted powers. Never match the text yourself.
- `contractedPowerkW` has one value per power period: 2 values for 2.0TD, 6 for the others. If the number disagrees with the text, the tariff is `null`.
- In the six-period tariffs the contracted power should not decrease from P1 to P6, but real data has broken the rule: do not enforce it.
- `codeFare` is the CNMC code of the tariff and is separate from `accessFare`.

## Tariff periods

**2.0TD** (Peninsula, Baleares and Canarias) has three energy periods:

- **P1 punta**: from 10:00 to 14:00 and from 18:00 to 22:00.
- **P2 llano**: from 8:00 to 10:00, from 14:00 to 18:00 and from 22:00 to 24:00.
- **P3 valle**: from 0:00 to 8:00.
- **Weekends and national holidays** are all P3. In Ceuta and Melilla the punta and llano blocks start one hour later and the valle still ends at 8:00.
- A reading's `hourOfDay` is the hour it starts (0 to 23), which is what `periodFor()` takes.

**3.0TD and 6.1TD to 6.4TD** share one six-period calendar that depends on the month (the season) and the territory. On working days the early hours (0 to 8) are P6, and the "high" hours are the morning and evening ones. The package ships both calendars: use them instead of writing your own.

- **National holidays** count as weekends: 1 and 6 January, 1 May, 15 August, 12 October, 1 November, 6, 8 and 25 December. Good Friday, regional and local holidays are not counted.
- The period of a reading is `$tariff->schedule($territory)->periodFor($reading->day, $reading->hourOfDay)`, a number from 1.

## Territories and time

- The first two digits of a postal code give the province: `07` Baleares, `35` and `38` Canarias, `51` Ceuta, `52` Melilla, `01` to `52` otherwise the Peninsula. `Territory::fromPostalCode()` returns `null` for anything else.
- The Peninsula, Baleares, Ceuta and Melilla use CET and CEST. The Canary Islands use WET and WEST: give the client `timezone` `Atlantic/Canary` for those supplies.
- The Canary Islands, Ceuta and Melilla have their own period hours and seasons in the six-period calendar.

## Maximum power, reactive energy and self-consumption

- **Maximum power** (`getMaxPowerOf()`) returns one row per tariff period, in kW, with the moment it was reached. `periodNumber()` gives the period. Its time marks the end of a quarter hour, so a peak at `00:00` belongs to the last quarter of the previous day. Compare it with the contracted power of the same period.
- **Reactive energy** (`getReactiveDataOf()`, API v2 only) is penalised in 3.0TD and 6.xTD, in every period except P6, when it exceeds 33 % of the active energy (a power factor below 0.95). The client only gives the values: you decide what to do with them.
- **Self-consumption**: a reading can carry `surplusEnergyKWh`, `generationEnergyKWh` and `selfConsumptionEnergyKWh` (null when the supply has none). The contract carries `selfConsumptionTypeCode`, `cau`, `installedCapacity` and `partitionCoefficient`. The client exposes them and does not interpret them; the codes come from RD 244/2019.

## Authorization

A holder (the owner of the supply) authorizes a third party's NIF inside Datadis, for a period of about two years, renewable, and not always instant. The third party asks with its own credentials and the holder's NIF as `authorizedNif`, never with the holder's password. An expired authorization shows up as `AuthorizationException`.

## What is available, and when

- Whole months within the last 24 months.
- The current month has data up to about two days ago, and a month can keep changing for some days after it ends. A run of trailing zeros in the current month is not real consumption.
- A distributor can fail while the others answer: read `distributorErrors`, and do not take an empty answer for "no consumption".

## Recipe: energy per tariff period

```php
use Brick\Math\BigDecimal;
use Lenorix\DatadisClient\Calendar\Territory;

$contract = $client->getContractDetailOf($supply)->records[0] ?? null;
$territory = Territory::fromPostalCode($supply->postalCode) ?? Territory::Peninsula;
$schedule = $contract?->tariff()?->schedule($territory);

$kWhPerPeriod = [];

foreach ($client->getConsumptionDataOf($supply, $month)->records as $reading) {
    if ($schedule === null || ! $reading->hasValidTime() || $reading->hourOfDay === null) {
        continue; // unknown tariff, or an extra row without a real time
    }

    $period = $schedule->periodFor($reading->day, $reading->hourOfDay);
    $kWhPerPeriod[$period] = ($kWhPerPeriod[$period] ?? BigDecimal::zero())->plus($reading->consumptionKWh);
}
```

## Do not

- Do not invent tariffs, hours or holidays: use `AccessTariff`, `schedule()` and `Territory`.
- Do not total with floats, and do not assume 24 rows a day.
- Do not read an empty answer as zero consumption, and do not trust a tariff that is `null`.
- Do not apply a regional or local holiday: the calendars do not know them.

## Sources

Circular CNMC 3/2020 (access tariffs and periods), RD 1110/2007 (metering points), RD 244/2019 (self-consumption) and the public Datadis API manual.
