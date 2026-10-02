<?php

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Values\Cups;

/** The recipe of the datadis-electricity-domain skill, run on a week day, a Saturday and a Canary Islands supply. */
function energyPerPeriod(string $postalCode, string $accessFare, array $powers): array
{
    $row = fn (string $date, string $time, float $kWh) => ['cups' => CUPS, 'date' => $date, 'time' => $time, 'consumptionKWh' => $kWh, 'obtainMethod' => 'Real'];

    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'postalCode' => $postalCode,
            'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-contract-detail*' => Http::response(['contract' => [[
            'cups' => CUPS, 'accessFare' => $accessFare, 'contractedPowerkW' => $powers, 'startDate' => '2020/01/01', 'endDate' => '',
        ]], 'distributorError' => []]),
        '*/get-consumption-data*' => Http::response(['timeCurve' => [
            $row('2026/07/01', '01:00', 0.1),   // Wednesday, the hour that starts at 0:00
            $row('2026/07/01', '11:00', 0.5),   // Wednesday, 10:00 to 11:00
            $row('2026/07/01', '15:00', 0.25),  // Wednesday, 14:00 to 15:00
            $row('2026/07/04', '12:00', 0.2),   // Saturday, 11:00 to 12:00
        ], 'distributorError' => []]),
    ]);

    $client = app(DatadisClient::class);
    $supply = $client->findSupply(Cups::fromString(CUPS));
    $month = monthsAgo();

    // --- the recipe ---
    $contract = $client->getContractDetailOf($supply)->records[0] ?? null;
    $territory = Territory::fromPostalCode($supply->postalCode) ?? Territory::Peninsula;
    $schedule = $contract?->tariff()?->schedule($territory);

    $kWhPerPeriod = [];

    foreach ($client->getConsumptionDataOf($supply, $month)->records as $reading) {
        if ($schedule === null || ! $reading->hasValidTime() || $reading->hourOfDay === null) {
            continue;
        }

        $period = $schedule->periodFor($reading->day, $reading->hourOfDay);
        $kWhPerPeriod[$period] = ($kWhPerPeriod[$period] ?? BigDecimal::zero())->plus($reading->consumptionKWh);
    }
    // --- end of the recipe ---

    ksort($kWhPerPeriod);

    return array_map(fn (BigDecimal $kWh) => (string) $kWh->toScale(3), $kWhPerPeriod);
}

it('totals the energy per 2.0TD period: valley at night and at weekends, peak at 10:00', function () {
    expect(energyPerPeriod('28001', 'BAJA TENSION y POTENCIA <= 15 kW', [3.45, 3.45]))
        ->toBe([1 => '0.500', 2 => '0.250', 3 => '0.300']);   // P3: the 0:00 hour (0.1) and the Saturday (0.2)
});

it('uses the Ceuta and Melilla hours, one hour later, for their postal codes', function () {
    // In Ceuta (51001) 10:00 to 11:00 is still llano (the peak starts at 11:00) and 14:00 to 15:00 is still peak.
    expect(energyPerPeriod('51001', 'BAJA TENSION y POTENCIA <= 15 kW', [3.45, 3.45]))
        ->toBe([1 => '0.250', 2 => '0.500', 3 => '0.300']);
});

it('gives no periods when the tariff cannot be told', function () {
    expect(energyPerPeriod('28001', 'SOMETHING UNKNOWN', [3.45, 3.45, 5.0]))->toBe([]);
});
