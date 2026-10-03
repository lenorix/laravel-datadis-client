<?php

use Illuminate\Support\Facades\Cache;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;
use Lenorix\LaravelDatadisClient\Tests\Support\NoLockStore;

/*
 * A store with its own time (as Redis has the server's) does not follow `travelTo()`: a held key stays held. What the
 * guard does with it decides what a test can promise about time travel.
 */
it('still refuses after a travelled window on a store that keeps its own time, as Redis does', function () {
    Cache::extend('owntime', fn () => Cache::repository(new NoLockStore));
    config()->set('cache.stores.owntime', ['driver' => 'owntime']);
    config()->set('cache.default', 'owntime');
    FakesDatadis::fake();
    $client = app(DatadisClient::class);
    $month = monthsAgo(2);

    $client->getMaxPowerOf(supplyOf($client), $month);

    $held = fn () => (fn () => array_keys($this->items))->call(Cache::store('owntime')->getStore());
    expect($held())->not->toBeEmpty();   // the premise: the key is in the store

    $this->travelTo(now()->addHours(25));
    expect($held())->not->toBeEmpty();   // ... and still there after the travel: the store did not move

    // The held key outlives the travelled window: the guard still refuses. Only a cache whose expiry follows `now()` (array, file,
    // database) lets a test move the guard through the window.
    expect(fn () => $client->getMaxPowerOf(supplyOf($client), $month))->toThrow(RepetitionWindowException::class);
    expect(guardedRequests())->toBe(1);
});
