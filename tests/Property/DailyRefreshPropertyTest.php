<?php

use Eris\Generators;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;

/**
 * The range of the daily refresh (getLatestConsumptionDataOf() and getLatestMaxPowerOf() ask MonthPlanner::latest())
 * over a run of consecutive days at the same local time, for every contract start and across every change of month.
 */
function supplyStartingOn(?DateTimeImmutable $from): Supply
{
    $zone = new DateTimeZone('Europe/Madrid');

    return Supply::fromRow(['cups' => CUPS, 'validDateFrom' => $from?->format('Y/m/d'), 'validDateTo' => ''], $zone);
}

/** @return list<array{0: string, 1: string}> the range of each of $days consecutive days, as text */
function dailyRanges(DateTimeImmutable $first, int $days, ?Supply $supply): array
{
    $ranges = [];

    for ($day = 0; $day < $days; $day++) {
        $now = $first->modify("+{$day} days");
        $range = MonthPlanner::latest($now, $supply);

        expect($range)->toHaveCount(1);
        $ranges[] = [$range[0][0]->format(), $range[0][1]->format()];
    }

    return $ranges;
}

it('always asks the current month, and the previous one at most', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(2024, 2028),
        Generators::choose(1, 12),
        Generators::choose(1, 28),
        Generators::choose(0, 23),
        Generators::choose(0, 59),
        Generators::choose(2, 70),
        Generators::choose(0, 400),
    )->then(function (int $year, int $month, int $day, int $hour, int $minute, int $days, int $startedDaysBefore) {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute), new DateTimeZone('Europe/Madrid'));
        $supply = supplyStartingOn($first->modify("-{$startedDaysBefore} days"));

        foreach (dailyRanges($first, $days, $supply) as $i => [$from, $to]) {
            $current = Month::current($first->modify("+{$i} days"));

            expect($to)->toBe($current->format());
            expect([$current->format(), $current->addMonths(-1)->format()])->toContain($from);
        }
    });
});

it('never repeats a query from one day to the next, except in the month the contract starts', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(2024, 2028),
        Generators::choose(1, 12),
        Generators::choose(1, 28),
        Generators::choose(0, 23),
        Generators::choose(0, 59),
        Generators::choose(2, 70),
        Generators::choose(0, 400),
    )->then(function (int $year, int $month, int $day, int $hour, int $minute, int $days, int $startedDaysBefore) {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute), new DateTimeZone('Europe/Madrid'));
        $start = $first->modify("-{$startedDaysBefore} days");
        $ranges = dailyRanges($first, $days, supplyStartingOn($start));

        for ($i = 1; $i < $days; $i++) {
            $inTheStartMonth = fn (int $day) => Month::current($first->modify("+{$day} days"))->equals(Month::fromDate($start));

            // The documented exception of the client: in the month the contract starts, the range is that month every day.
            $repeats = $inTheStartMonth($i - 1) && $inTheStartMonth($i);

            expect($ranges[$i] === $ranges[$i - 1])->toBe($repeats, "day {$i} of a run from {$first->format('Y-m-d H:i')}, contract from {$start->format('Y-m-d')}");
        }
    });
});

it('never repeats a query from one day to the next when the contract start is unknown', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(2024, 2028),
        Generators::choose(1, 12),
        Generators::choose(1, 28),
        Generators::choose(2, 70),
    )->then(function (int $year, int $month, int $day, int $days) {
        $ranges = dailyRanges(new DateTimeImmutable(sprintf('%04d-%02d-%02d 04:00', $year, $month, $day), new DateTimeZone('Europe/Madrid')), $days, null);

        for ($i = 1; $i < $days; $i++) {
            expect($ranges[$i])->not->toBe($ranges[$i - 1]);
        }
    });
});

it('asks nothing for a contract that ended before this month or has not started', function () {
    $zone = new DateTimeZone('Europe/Madrid');
    $now = new DateTimeImmutable('2026-10-15 04:00', $zone);

    $ended = Supply::fromRow(['cups' => CUPS, 'validDateFrom' => '2020/01/01', 'validDateTo' => '2026/08/31'], $zone);
    $future = Supply::fromRow(['cups' => CUPS, 'validDateFrom' => '2026/11/05', 'validDateTo' => ''], $zone);

    expect(MonthPlanner::latest($now, $ended))->toBe([]);
    expect(MonthPlanner::latest($now, $future))->toBe([]);
});
