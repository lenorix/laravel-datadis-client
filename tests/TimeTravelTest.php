<?php

use Illuminate\Support\Carbon;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Time\Month;

/*
 * The package takes its time from the application (`now()`), so a test can run a daily job over several days with
 * `travelTo()` and see the guard, the range of the daily refresh and the token move together.
 */
afterEach(fn () => Carbon::setTestNow());

it('refreshes every day with a range that differs from the day before, over days of travelled time', function () {
    fakeEverything();
    $ranges = [];

    foreach (range(0, 5) as $day) {
        $this->travelTo(Carbon::parse('2026-03-26 04:00', 'Europe/Madrid')->addDays($day));   // across the change of month

        $result = app(DatadisClient::class)->getLatestConsumptionDataOf(supplyOf(app(DatadisClient::class)));

        $ranges[] = $result->startDate->format().'-'.$result->endDate->format();
    }

    expect(guardedRequests())->toBe(6);   // none refused
    for ($i = 1; $i < 6; $i++) {
        expect($ranges[$i])->not->toBe($ranges[$i - 1]);
    }
});

it('refuses the same query within the window and lets it through after it, as time moves', function () {
    fakeEverything();
    $this->travelTo(Carbon::parse('2026-07-10 04:00', 'Europe/Madrid'));
    $client = app(DatadisClient::class);
    $month = Month::of(2026, 5);

    $client->getMaxPowerOf(supplyOf($client), $month);
    $this->travelTo(now()->addHours(24));   // inside the window of 24 hours and 10 minutes
    expect(fn () => $client->getMaxPowerOf(supplyOf($client), $month))->toThrow(RepetitionWindowException::class);

    $this->travelTo(now()->addMinutes(11));   // outside it
    $client->getMaxPowerOf(supplyOf($client), $month);

    expect(guardedRequests())->toBe(2);
});

it('keeps the token for as long as the application time says, and logs in again after it', function () {
    fakeEverything();
    $this->travelTo(Carbon::parse('2026-07-10 04:00', 'Europe/Madrid'));

    app(DatadisClient::class)->getSupplies();
    $this->travelTo(now()->addHours(12));
    app(DatadisClient::class)->getSupplies();
    expect(logins())->toHaveCount(1);

    $this->travelTo(now()->addHours(13));   // the token of 24 hours has expired
    app(DatadisClient::class)->getSupplies();
    expect(logins())->toHaveCount(2);
});

it('tells which months a refused daily refresh did not get', function () {
    fakeEverything();
    $this->travelTo(Carbon::parse('2026-10-03 04:00', 'Europe/Madrid'));   // an odd day: the previous month and the current one
    $client = app(DatadisClient::class);
    $supply = supplyOf($client);

    $client->getLatestConsumptionDataOf($supply);

    try {
        $client->getLatestConsumptionDataOf($supply);   // the same day again
    } catch (RepetitionWindowException $e) {
        expect([$e->startDate->format(), $e->endDate->format()])->toBe(['2026/09', '2026/10']);
        expect($e->availableAt)->not->toBeNull();

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
});
