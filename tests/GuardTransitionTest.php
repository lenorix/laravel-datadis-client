<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\Endpoint;
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
        fn ($m) => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $m, pointType: 5),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'quarter-hourly consumption' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $m, pointType: 5, measurementType: MeasurementType::QuarterHourly),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly),
    ],
    'consumption of a range' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $m->addMonths(-1), $m, pointType: 5),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(-1), $m),
    ],
    'consumption for a holder' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $m, pointType: 5, authorizedNif: Nif::fromString('12345678Z')),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'consumption of the account itself, given with its own NIF' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $m, pointType: 5, authorizedNif: Nif::fromString('00000000T')),
        fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m),
    ],
    'maximum power' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m),
    ],
    'maximum power, whoever the holder is (Datadis does not key it on the holder)' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getMaxPowerOf(supplyOf($c), $m),
    ],
    'reactive energy' => [
        fn ($m) => Datadis::rememberAttempt(Endpoint::Reactive, Cups::fromString(CUPS), '2', $m),
        fn ($c, $m) => $c->getReactiveDataOf(supplyOf($c), $m),
    ],
]);

it('does not block a query that differs from the one remembered', function (Closure $send) {
    fakeEverything();
    $month = monthsAgo(2);
    Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', $month, pointType: 5);

    $send(app(DatadisClient::class), $month);

    expect(guardedRequests())->toBe(1);
})->with([
    'another month' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m->addMonths(1))],
    'quarter-hourly instead of hourly' => [fn ($c, $m) => $c->getConsumptionDataOf(supplyOf($c), $m, null, MeasurementType::QuarterHourly)],
    'another holder' => [fn ($c, $m) => $c->forHolder(Nif::fromString('12345678Z'))->getConsumptionDataOf(supplyOf($c), $m)],
    'maximum power, which was not remembered' => [fn ($c, $m) => $c->getMaxPowerOf(supplyOf($c), $m)],
]);

it('remembers for what is left of the window, from the time the query was sent', function () {
    $record = fn (int $secondsAgo) => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable("-{$secondsAgo} seconds"));

    // Just inside the window (24 h and 10 min): still blocks, and the time kept is the original one.
    expect($record(RequestLedger::WINDOW_SECONDS - 60))->toBeTrue();

    // The attempt kept is the first one: asking again, even with a later time, does not overwrite it.
    expect(Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable))->toBeFalse();

    // Out of the window: nothing to protect, so nothing is recorded.
    expect(Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(3), at: new DateTimeImmutable('-'.(RequestLedger::WINDOW_SECONDS + 1).' seconds')))->toBeFalse();
    fakeEverything();
    app(DatadisClient::class)->getMaxPowerOf(supplyOf(app(DatadisClient::class)), monthsAgo(3));
    expect(guardedRequests())->toBe(1);
});

it('keeps the original time of the query, not the moment it was remembered', function () {
    $sentAt = new DateTimeImmutable('-20 hours');
    Datadis::rememberAttempt(Endpoint::Reactive, Cups::fromString(CUPS), '2', monthsAgo(2), at: $sentAt);

    $ledger = (fn () => $this->ledger())->call(app(Manager::class));
    $last = $ledger->lastAttempt('00000000T', ['cups' => CUPS, 'distributorCode' => '2', 'startDate' => monthsAgo(2)->format(), 'endDate' => monthsAgo(2)->format(), 'authorizedNif' => null]);

    expect($last?->getTimestamp())->toBe($sentAt->getTimestamp());
});

it('does not take a newer attempt back to an older time', function () {
    fakeEverything();
    $client = app(DatadisClient::class);
    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));   // sent now

    expect(Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-5 hours')))->toBeFalse();
});

it('keys the entries on the account it is given', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'x']);
    fakeEverything();
    Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), account: 'other');

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
    'an endpoint the rule does not cover' => [fn () => Datadis::rememberAttempt(Endpoint::Supplies, Cups::fromString(CUPS), '2', monthsAgo(2)), 'subject to the 24 hour rule'],
    'a consumption without its point type' => [fn () => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', monthsAgo(2)), 'point type'],
    'a point type out of range' => [fn () => Datadis::rememberAttempt(Endpoint::Consumption, Cups::fromString(CUPS), '2', monthsAgo(2), pointType: 9), 'point type'],
    'a bad distributor code' => [fn () => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), 'not a code!', monthsAgo(2)), 'distributor code'],
    'a time in the future' => [fn () => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('+2 hours')), 'future'],
    'an account that is not configured' => [fn () => Datadis::rememberAttempt(Endpoint::MaxPower, Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'), 'not configured'],
]);
