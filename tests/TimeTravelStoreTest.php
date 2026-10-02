<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;

/** An array store whose lifetimes follow the system clock, as Redis follows its server's: `travelTo()` does not reach it. */
class OwnClockStore extends ArrayStore
{
    protected function currentTime()
    {
        return time();
    }
}

it('lets a query through after the window, as the application time moves, even on a store that keeps its own time', function () {
    Cache::extend('ownclock', fn () => Cache::repository(new OwnClockStore));
    config()->set('cache.stores.ownclock', ['driver' => 'ownclock']);
    config()->set('cache.default', 'ownclock');
    fakeEverything();
    $this->travelTo(Carbon::parse('2026-07-10 04:00', 'Europe/Madrid'));
    $client = app(DatadisClient::class);
    $month = Month::of(2026, 5);

    $client->getMaxPowerOf(supplyOf($client), $month);
    $this->travelTo(now()->addHours(25));

    // The held key is still in the store (its own clock has not moved), yet the ledger reads the time it holds against the
    // application's: after the window the query is free.
    $client->getMaxPowerOf(supplyOf($client), $month);

    expect(guardedRequests())->toBe(2);
});
