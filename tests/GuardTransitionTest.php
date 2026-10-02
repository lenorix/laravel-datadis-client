<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

/** How many guarded requests reached Datadis. */
function guardedRequests(): int
{
    return Http::recorded(fn (Request $r) => preg_match('/get-(consumption-data|max-power|reactive-data)/', $r->url()) === 1)->count();
}

/** The supply the client finds, so that the Of() calls can be made. */
function supplyOf(DatadisClient $client)
{
    return $client->findSupply(Cups::fromString(CUPS));
}

it('makes the client refuse what a record of your own says was sent', function (Closure $remember, Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);

    expect($remember($month))->toBeTrue();

    expect(fn () => $send(app(DatadisClient::class), $month))->toThrow(RepetitionWindowException::class);
    expect(guardedRequests())->toBe(0);
})->with([
    'hourly consumption' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'quarter-hourly consumption' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, measurementType: MeasurementType::QuarterHourly),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly),
    ],
    'consumption of a range' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m->addMonths(-1), $m),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(-1), $m),
    ],
    'consumption for a holder' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, authorizedNif: Nif::fromString('12345678Z')),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'consumption of the account itself, given with its own NIF' => [
        fn ($m) => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $m, authorizedNif: Nif::fromString('00000000T')),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'maximum power' => [
        fn ($m) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m),
    ],
    'maximum power, whoever the holder is (Datadis does not key it on the holder)' => [
        fn ($m) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getMaxPowerOf(supplyOf($c), $m),
    ],
    'reactive energy' => [
        fn ($m) => Datadis::rememberReactive(Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getReactiveDataOf(supplyOf($c), $m),
    ],
]);

it('does not block a query that differs from the one remembered', function (Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);
    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, $month);

    $send(app(DatadisClient::class), $month);

    expect(guardedRequests())->toBe(1);
})->with([
    'another month' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(1))],
    'quarter-hourly instead of hourly' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly)],
    'another holder' => [fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m)],
    'maximum power, which was not remembered' => [fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m)],
]);

it('remembers for what is left of the window, from the time the query was sent', function () {
    $record = fn (int $secondsAgo) => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$secondsAgo} seconds"));

    // Just inside the window (24 h and 10 min): still blocks, and the time kept is the original one.
    expect($record(RequestLedger::WINDOW_SECONDS - 60))->toBeTrue();

    // The attempt kept is the first one: asking again, even with a later time, does not overwrite it.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable))->toBeFalse();

    // Out of the window: nothing to protect, so nothing is recorded.
    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(3), at: new DateTimeImmutable('-'.(RequestLedger::WINDOW_SECONDS + 1).' seconds')))->toBeFalse();
    fakeEverything();
    app(DatadisClient::class)->getMaxPowerOf(supplyOf(app(DatadisClient::class)), monthsAgo(3));
    expect(guardedRequests())->toBe(1);
});

it('keeps the original time of the query, not the moment it was remembered', function () {
    $sentAt = new DateTimeImmutable('-20 hours');
    Datadis::rememberReactive(Cups::fromString(CUPS), '2', monthsAgo(2), at: $sentAt);

    $ledger = (fn () => $this->ledger())->call(app(Manager::class));
    $last = $ledger->lastAttempt('00000000T', ['cups' => CUPS, 'distributorCode' => '2', 'startDate' => monthsAgo(2)->format(), 'endDate' => monthsAgo(2)->format(), 'authorizedNif' => null]);

    expect($last?->getTimestamp())->toBe($sentAt->getTimestamp());
});

it('does not take a newer attempt back to an older time', function () {
    fakeEverything();
    $client = app(DatadisClient::class);
    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));   // sent now

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-5 hours')))->toBeFalse();
});

it('keys the entries on the account it is given', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'x']);
    fakeEverything();
    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'other');

    // The default account is not blocked by what was remembered for another one.
    $client = app(DatadisClient::class);
    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));
    expect(guardedRequests())->toBe(1);

    // The other account is.
    $other = app(Manager::class)->account('other');
    expect(fn () => $other->getMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2)))->toThrow(RepetitionWindowException::class);
});

it('refuses what cannot be remembered', function (Closure $call, string $message) {
    expect($call)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a point type out of range' => [fn () => Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 9, monthsAgo(2)), 'point type'],
    'a bad distributor code' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), 'not a code!', monthsAgo(2)), 'distributor code'],
    'a time in the future' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('+2 hours')), 'future'],
    'an account that is not configured' => [fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'), 'not configured'],
]);

/** An array store that notes the lifetime each key was stored with (Laravel's add() on it is a get and a put). */
class TtlSpyStore extends ArrayStore
{
    /** @var array<string, int> */
    public static array $ttls = [];

    public function put($key, $value, $seconds)
    {
        self::$ttls[$key] = (int) $seconds;

        return parent::put($key, $value, $seconds);
    }
}

it('keeps an old attempt only for what is left of its window, not for a whole new one', function (int $hoursAgo, int $expected) {
    TtlSpyStore::$ttls = [];
    Cache::extend('spy', fn () => Cache::repository(new TtlSpyStore));
    config()->set('cache.stores.spy', ['driver' => 'spy']);
    config()->set('datadis-client.cache.store', 'spy');

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$hoursAgo} hours"));

    $ledgerKeys = array_filter(TtlSpyStore::$ttls, fn ($ttl, $key) => str_starts_with($key, 'datadis_query_'), ARRAY_FILTER_USE_BOTH);

    expect($ledgerKeys)->toHaveCount(1);
    // What is left of 24 h 10 min, give or take the seconds the test takes.
    expect(array_values($ledgerKeys)[0])->toBeGreaterThanOrEqual($expected - 5)->toBeLessThanOrEqual($expected + 5);
})->with([
    'sent just now: the whole window' => [0, RequestLedger::WINDOW_SECONDS],
    'sent 12 hours ago: half of it' => [12, RequestLedger::WINDOW_SECONDS - 12 * 3600],
    'sent 23 hours ago: an hour and ten minutes' => [23, RequestLedger::WINDOW_SECONDS - 23 * 3600],
]);
