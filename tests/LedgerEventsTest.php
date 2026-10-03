<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

it('tells that a query was claimed when it goes out, and says nothing of a refusal', function () {
    $seen = [];
    Event::listen(DatadisLedgerChanged::class, function (DatadisLedgerChanged $event) use (&$seen) {
        $seen[] = $event;
    });
    fakeEverything();
    $client = app(DatadisClient::class);

    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));
    expect($seen)->toHaveCount(1);
    expect($seen[0]->kind)->toBe(LedgerEventKind::Claimed);
    expect($seen[0]->account)->toBe('default');
    expect($seen[0]->endpoint)->toContain('max-power');
    expect(abs($seen[0]->at->getTimestamp() - time()))->toBeLessThan(5);

    // A refusal is the exception, not an event.
    expect(fn () => $client->getMaxPowerOf(supplyOf($client), monthsAgo(2)))->toThrow(RepetitionWindowException::class);
    expect($seen)->toHaveCount(1);
});

it('tells that a query was released when it never left', function () {
    $seen = [];
    Event::listen(DatadisLedgerChanged::class, function (DatadisLedgerChanged $event) use (&$seen) {
        $seen[] = $event;
    });
    Http::fake(['*/nikola-auth/tokens/login' => fn () => throw new ConnectionException('refused')]);
    $client = app(DatadisClient::class);

    expect(fn () => $client->getConsumptionData(Cups::fromString(CUPS), '2', 5, monthsAgo(2)))->toThrow(TransportException::class);

    expect(array_map(fn ($event) => $event->kind, $seen))->toBe([LedgerEventKind::Claimed, LedgerEventKind::Released]);
    expect($seen[0]->key)->toBe($seen[1]->key);   // the same query
});

it('tells that an attempt was remembered, under the name of the account', function () {
    config()->set('datadis-client.accounts', [
        'default' => ['username' => '00000000T', 'password' => 'x'],
        'tenant.east' => ['username' => '12345678Z', 'password' => 'y'],
    ]);
    $seen = [];
    Event::listen(DatadisLedgerChanged::class, function (DatadisLedgerChanged $event) use (&$seen) {
        $seen[] = $event;
    });

    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour'), account: 'tenant.east');
    Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(3), at: new DateTimeImmutable('-1 hour'));

    expect(array_map(fn ($event) => [$event->kind, $event->account], $seen))->toBe([
        [LedgerEventKind::Remembered, 'tenant.east'],
        [LedgerEventKind::Remembered, 'default'],
    ]);
});

it('carries no personal data: neither the CUPS, nor the username, nor the NIF of a holder', function () {
    $seen = [];
    Event::listen(DatadisLedgerChanged::class, function (DatadisLedgerChanged $event) use (&$seen) {
        $seen[] = $event;
    });
    fakeEverything();
    $client = app(DatadisClient::class)->forHolder(Nif::fromString('12345678Z'));

    $client->getConsumptionDataOf(supplyOf($client), monthsAgo(2));

    expect($seen)->not->toBeEmpty();
    // What Telescope or a log would record of the event: every property, serialised.
    $recorded = json_encode($seen).serialize($seen).var_export($seen, true);
    expect($recorded)->not->toContain(CUPS)->not->toContain('00000000T')->not->toContain('12345678Z');
});

it('does not let a listener that throws decide whether a query goes', function () {
    Event::listen(DatadisLedgerChanged::class, function () {
        throw new RuntimeException('a broken listener');
    });
    fakeEverything();
    $client = app(DatadisClient::class);

    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));

    expect(guardedRequests())->toBe(1);
    // ... and the guard still knows it: the listener broke nothing.
    expect(fn () => $client->getMaxPowerOf(supplyOf($client), monthsAgo(2)))->toThrow(RepetitionWindowException::class);
});

it('reaches an Event::fake() set after the client was built', function () {
    fakeEverything();
    $client = app(DatadisClient::class);
    Event::fake([DatadisLedgerChanged::class]);

    $client->getMaxPowerOf(supplyOf($client), monthsAgo(2));

    Event::assertDispatched(DatadisLedgerChanged::class, fn ($event) => $event->kind === LedgerEventKind::Claimed && $event->account === 'default');
});
