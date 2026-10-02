---
name: datadis-electricity-domain
description: Spanish electricity domain knowledge for reading Datadis data - CUPS, access tariffs (2.0TD to 6.4TD), tariff periods P1 to P6 and their calendars, contracted and maximum power, point types, quarter-hourly data, reactive energy, self-consumption, territories and time zones, and the authorization model. Use when interpreting Datadis fields or computing anything from consumption, power or tariffs.
---

# Datadis Electricity Domain

## When to use this skill

Use it when you must understand what a Datadis field means, or compute something that depends on the Spanish electricity rules: consumption per tariff period, contracted against maximum power, a comparison of tariffs, a check of reactive energy. For the calls themselves read `datadis-development`.

## Units and shapes

*Source: the Datadis API manual (sections 4.3 and 4.4; see [Sources](#sources)) and the answers observed by the client ([API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md)).*

- **Energy** is in kWh (`consumptionKWh`, `surplusEnergyKWh`, `generationEnergyKWh`). **Power** is in kW (`contractedPowerkW` and the maximum power, although the manual says watts).
- **Reactive energy** is billed in kVArh. What Datadis returns for it (API v2 only) has not been seen with data yet, so do not assume its unit.
- Values arrive as decimal strings and keep every digit Datadis sends. Add them with `Brick\Math\BigDecimal`, never with floats.
- A reading is **hourly** (24 a day, in a daylight saving change 23 or 25) or **quarter-hourly** (96 a day). Each label marks the **end** of the interval, so `24:00` is the last hour.
- `obtainMethod` says where a value comes from: `isReal()` is a meter reading, `isEstimated()` an estimate, and any other text is unknown. Keep that when you total, or tell the user.

## The supply: CUPS and its codes

*Sources: the [CNMC guide to the CUPS](https://www.cnmc.es/sites/default/files/editor_contenidos/Energia/Consumidores/3.1.%20El%20CUPS.pdf) for its structure, [RD 1110/2007, art. 7](https://www.boe.es/buscar/act.php?id=BOE-A-2007-16478#a7) for the point types, and the Datadis API manual, section 4.1, for the codes.*

- A **CUPS** identifies a supply point, not its holder, and is permanent. It is `ES`, the distributor's digits and 12 digits of the point (16 digits in all), 2 control letters, and sometimes a border point digit and letter more (20 or 22 characters, for example `ES0000000000000000AA0A`). Datadis wants it exactly as listed.
- Every data call also needs the **distributor code** (a short text: the manual says a number from 1 to 8, but treat it as opaque) and the **point type**. Both come from the supplies list.
- The **point type** (`pointType`) is a whole number from 1 to 5. For a consumer, RD 1110/2007 (art. 7) classifies it by contracted power in any period:

| Type | Contracted power of a consumer |
|---|---|
| 1 | 10 MW or more |
| 2 | above 450 kW |
| 3 | every point that fits no other type (in practice, above 50 kW up to 450 kW) |
| 4 | above 15 kW up to 50 kW |
| 5 | 15 kW or less (homes and small businesses) |

The Real Decreto also classifies generation borders and other borders; those are not consumers.

- Quarter-hourly data (`measurementType` 1): a type 5 supply answers an empty list, which is not an error and not zero consumption. Datadis offers it for the larger types; the labels of those answers have not been verified.

## Access tariffs

*Source: [Circular CNMC 3/2020, art. 6.2](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066#a6).*

The access tariff (`peaje de acceso`, Circular CNMC 3/2020) fixes how many periods a supply has and how its hours are priced.

| Tariff | Who | Energy periods | Power periods |
|---|---|---|---|
| 2.0TD | up to 1 kV, 15 kW or less in every period | 3 (P1 to P3) | 2 |
| 3.0TD | up to 1 kV, above 15 kW in at least one period | 6 | 6 |
| 6.1TD | above 1 kV and below 30 kV | 6 | 6 |
| 6.2TD | 30 kV or more and below 72.5 kV | 6 | 6 |
| 6.3TD | 72.5 kV or more and below 145 kV | 6 | 6 |
| 6.4TD | 145 kV or more | 6 | 6 |

- `$contract->tariff()` returns an `AccessTariff` or `null` when it is unsure. Datadis sends `accessFare` as free text (`BAJA TENSION y POTENCIA <= 15 kW`), so the client reads its shape and checks it against the contracted powers. Never match the text yourself.
- `contractedPowerkW` has one value per power period: 2 values for 2.0TD, 6 for the others. If the number disagrees with the text, the tariff is `null`.
- In the six-period tariffs the Circular requires the contracted power not to decrease from P1 to P6 (art. 6.2), but real Datadis data has broken the rule: do not enforce it.
- `codeFare` is the CNMC code of the tariff and is separate from `accessFare`.

## Tariff periods

*Sources: [Circular CNMC 3/2020, art. 7.3](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066#a7) for 2.0TD and the holidays, and [art. 7.2](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066#a7) for the six-period calendar.*

**2.0TD** (Peninsula, Baleares and Canarias) has three energy periods:

- **P1 punta**: from 10:00 to 14:00 and from 18:00 to 22:00.
- **P2 llano**: from 8:00 to 10:00, from 14:00 to 18:00 and from 22:00 to 24:00.
- **P3 valle**: from 0:00 to 8:00.
- **Valle all day** on Saturdays, Sundays, 6 January and the national holidays. In Ceuta and Melilla the punta and llano blocks start one hour later (punta 11:00 to 15:00 and 19:00 to 23:00) and the valle still ends at 8:00.
- A reading's `hourOfDay` is the hour it starts (0 to 23), which is what `periodFor()` takes.

**3.0TD and 6.1TD to 6.4TD** share one six-period calendar that depends on the month (the season) and the territory. P6 covers 0:00 to 8:00 every day, and all day on weekends, 6 January and national holidays. On working days the other hours are P1 to P5 by season and by high or medium hours. The package ships both calendars: use them instead of writing your own.

- **Holidays** are the national ones with a fixed date that regions cannot substitute, plus 6 January: 1 and 6 January, 1 May, 15 August, 12 October, 1 November, 6, 8 and 25 December. Good Friday and every regional or local holiday do not count.
- The period of a reading is `$tariff->schedule($territory)->periodFor($reading->day, $reading->hourOfDay)`, a number from 1.

## Territories and time

*Source: the periods per territory are in the Circular, art. 7.2 and 7.3; the postal code mapping is the one the client's `Territory` implements.*

- The first two digits of a postal code give the province: `07` Baleares, `35` and `38` Canarias, `51` Ceuta, `52` Melilla, `01` to `52` otherwise the Peninsula. `Territory::fromPostalCode()` returns `null` for anything else.
- The Peninsula, Baleares, Ceuta and Melilla use CET and CEST. The Canary Islands use WET and WEST: give the client `timezone` `Atlantic/Canary` for those supplies.
- The Canary Islands, Ceuta and Melilla have their own period hours and seasons in the six-period calendar.

## Maximum power, reactive energy and self-consumption

*Sources: the Datadis API manual (4.2 contract detail, 4.4 maximum power), the [API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md) for the unit of the maximum power, and [Circular CNMC 3/2020, art. 9.5](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066#a9) for the reactive energy.*

- **Maximum power** (`getMaxPowerOf()`) returns one row per tariff period, in kW, with the date and time it was reached; `periodNumber()` gives the period. The time looks like the end of a quarter hour (a peak at `00:00` fits the last quarter of the previous day), which has not been verified. Compare it with the contracted power of the same period.
- **Reactive energy** (`getReactiveDataOf()`, API v2 only). The Circular bills the excess of reactive energy to every supply except 2.0TD (low voltage, 15 kW or less): in all periods except P6, when the reactive energy of the billing period exceeds 33 % of the active, and only the excess is billed. The client only gives the values: apply the rule yourself, over the whole billing period.
- **Self-consumption**: a reading can carry `surplusEnergyKWh`, `generationEnergyKWh` and `selfConsumptionEnergyKWh` (null when the supply has none). The contract carries `selfConsumptionTypeCode` with its description in words, `cau`, `installedCapacity` and `partitionCoefficient`. The client exposes them and does not interpret them.

## Authorization

*Source: the Datadis API manual (the `authorizedNif` parameter, section 4.1) and the client's [API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md).*

A holder (the owner of the supply) authorizes a third party's NIF inside Datadis. The third party asks with its own credentials and the holder's NIF as `authorizedNif`, never with the holder's password. Each authorization has a status and a validity period (`listAuthorization()` shows them); a missing or expired one shows up as `AuthorizationException`.

## What is available, and when

*Sources: the Datadis API manual (the 24 hour control, sections 4.3 and 4.4) and the refusals observed by the client ([quirks and rules](https://github.com/lenorix/datadis-php-client/blob/main/docs/quirks-and-rules.md)).*

- Whole months within the last 24 months; the boundary month, exactly two years back, is refused.
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

Official sources, to cite when you explain a rule or a figure to the user:

- [Circular CNMC 3/2020](https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066) (BOE-A-2020-1066): art. 6.2 access tariffs, art. 7.2 and 7.3 periods and holidays, art. 9.5 reactive energy.
- [Real Decreto 1110/2007](https://www.boe.es/buscar/act.php?id=BOE-A-2007-16478#a7) (BOE-A-2007-16478), art. 7: classification of the measurement points.
- [CNMC, "El CUPS"](https://www.cnmc.es/sites/default/files/editor_contenidos/Energia/Consumidores/3.1.%20El%20CUPS.pdf): what the CUPS is and its structure.
- [Datadis](https://datadis.es) API manual, in the API section of the site ([datadis.es/private-api](https://datadis.es/private-api); it asks for a Datadis login): endpoints, parameters and answers.
- Behaviour of the real service, with the evidence of each point: the client's [API reference](https://github.com/lenorix/datadis-php-client/blob/main/docs/api-reference.md), [quirks and rules](https://github.com/lenorix/datadis-php-client/blob/main/docs/quirks-and-rules.md) and [domain notes](https://github.com/lenorix/datadis-php-client/blob/main/docs/domain-knowledge.md).

What the sources do not say is marked here as not verified: which point types return quarter-hourly data, the unit of Datadis's reactive values and the meaning of the time of a maximum power row.
