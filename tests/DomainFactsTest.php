<?php

use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tariff\FixedSchedulePeriods;
use Lenorix\DatadisClient\Tariff\SixPeriodSchedule;

/**
 * The facts of the datadis-electricity-domain skill, pinned to what the package implements, so the skill cannot
 * drift from it. Their sources: Circular CNMC 3/2020 (BOE-A-2020-1066) articles 6 and 7.
 */
function hoursOf(FixedSchedulePeriods|SixPeriodSchedule $schedule, string $day): array
{
    return array_map(fn (int $hour) => $schedule->periodFor(new DateTimeImmutable($day), $hour), range(0, 23));
}

it('has the periods the skill says for each access tariff', function (AccessTariff $tariff, int $energy, int $power) {
    expect([$tariff->energyPeriods(), $tariff->powerPeriods()])->toBe([$energy, $power]);
})->with([
    '2.0TD' => [AccessTariff::T20TD, 3, 2],
    '3.0TD' => [AccessTariff::T30TD, 6, 6],
    '6.1TD' => [AccessTariff::T61TD, 6, 6],
    '6.2TD' => [AccessTariff::T62TD, 6, 6],
    '6.3TD' => [AccessTariff::T63TD, 6, 6],
    '6.4TD' => [AccessTariff::T64TD, 6, 6],
]);

it('has the 2.0TD hours of the skill on a working day', function () {
    $wednesday = '2026-07-01';

    // Peninsula, Baleares and Canarias: punta 10-14 and 18-22, llano 8-10, 14-18 and 22-24, valle 0-8.
    $expected = array_merge(array_fill(0, 8, 3), [2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2, 2]);

    foreach ([Territory::Peninsula, Territory::Baleares, Territory::Canarias] as $territory) {
        expect(hoursOf(new FixedSchedulePeriods($territory), $wednesday))->toBe($expected);
    }

    // Ceuta and Melilla: punta 11-15 and 19-23, llano 8-11, 15-19 and 23-24, valle 0-8.
    $ceutaMelilla = array_merge(array_fill(0, 8, 3), [2, 2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2]);

    foreach ([Territory::Ceuta, Territory::Melilla] as $territory) {
        expect(hoursOf(new FixedSchedulePeriods($territory), $wednesday))->toBe($ceutaMelilla);
    }
});

it('is valle all day on weekends and on the national holidays of the skill, and not on Good Friday', function (string $day) {
    expect(hoursOf(new FixedSchedulePeriods, $day))->toBe(array_fill(0, 24, 3));
    expect(array_unique(hoursOf(new SixPeriodSchedule, $day)))->toBe([6]);   // P6 all day for the six-period tariffs too
})->with([
    'Saturday' => '2026-07-04',
    'Sunday' => '2026-07-05',
    '1 January, a Thursday' => '2026-01-01',
    '6 January, a Tuesday' => '2026-01-06',
    '1 May, a Friday' => '2026-05-01',
    '15 August, a Friday' => '2025-08-15',
    '12 October, a Monday' => '2026-10-12',
    '1 November, a Monday' => '2027-11-01',
    '6 December, a Monday' => '2027-12-06',
    '8 December, a Tuesday' => '2026-12-08',
    '25 December, a Friday' => '2026-12-25',
]);

it('does not count Good Friday nor a regional holiday', function (string $day) {
    expect(hoursOf(new FixedSchedulePeriods, $day))->not->toBe(array_fill(0, 24, 3));
})->with([
    'Good Friday' => '2026-04-03',
    'Easter Monday, a regional holiday' => '2026-04-06',
    'Saint Joseph, a regional holiday' => '2026-03-19',
]);

it('has P6 from 0:00 to 8:00 on every working day in the six-period tariffs, in every territory', function (Territory $territory) {
    foreach (['2026-01-14', '2026-04-15', '2026-07-15', '2026-10-14'] as $day) {
        $periods = hoursOf(new SixPeriodSchedule($territory), $day);

        expect(array_slice($periods, 0, 8))->toBe(array_fill(0, 8, 6));
        expect(array_slice($periods, 8))->not->toContain(6);   // from 8:00 on, P1 to P5
    }
})->with([Territory::Peninsula, Territory::Baleares, Territory::Canarias, Territory::Ceuta, Territory::Melilla]);

it('has the Peninsula seasons of the skill: P1 only in January, February, July and December', function () {
    $highSeason = [1, 2, 7, 12];

    foreach (range(1, 12) as $month) {
        $day = sprintf('2026-%02d-15', $month);
        while ((int) (new DateTimeImmutable($day))->format('N') > 5) {
            $day = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');   // a working day
        }

        $hasP1 = in_array(1, hoursOf(new SixPeriodSchedule(Territory::Peninsula), $day), true);

        expect($hasP1)->toBe(in_array($month, $highSeason, true), "month {$month}");
    }
});

it('maps postal codes to territories as the skill says', function (?string $postalCode, ?Territory $territory) {
    expect(Territory::fromPostalCode($postalCode))->toBe($territory);
})->with([
    'Madrid' => ['28001', Territory::Peninsula],
    'Baleares' => ['07001', Territory::Baleares],
    'Las Palmas' => ['35001', Territory::Canarias],
    'Santa Cruz de Tenerife' => ['38001', Territory::Canarias],
    'Ceuta' => ['51001', Territory::Ceuta],
    'Melilla' => ['52001', Territory::Melilla],
    'no province 00' => ['00001', null],
    'no province 53' => ['53001', null],
    'not a postal code' => ['abc', null],
    'missing' => [null, null],
]);

it('keeps the Canary Islands on their own time zone and everyone else on Madrid\'s', function (Territory $territory, string $zone) {
    expect($territory->timeZone()->getName())->toBe($zone);
})->with([
    [Territory::Canarias, 'Atlantic/Canary'],
    [Territory::Peninsula, 'Europe/Madrid'],
    [Territory::Baleares, 'Europe/Madrid'],
    [Territory::Ceuta, 'Europe/Madrid'],
    [Territory::Melilla, 'Europe/Madrid'],
]);
