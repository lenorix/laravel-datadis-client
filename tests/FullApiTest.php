<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

/**
 * Every public method of the client, read and write, reached through the facade and through the injected
 * client: [what to call, the endpoint it must hit].
 *
 * @return array<string, array{Closure, string}>
 */
function everyClientCall(): array
{
    $cups = fn () => Cups::fromString(CUPS);
    $nif = fn () => Nif::fromString('12345678Z');

    return [
        'getSupplies' => [fn ($c) => $c->getSupplies(), 'get-supplies'],
        'findSupply' => [fn ($c) => $c->findSupply($cups()), 'get-supplies'],
        'getDistributorsWithSupplies' => [fn ($c) => $c->getDistributorsWithSupplies(), 'get-distributors-with-supplies'],
        'getContractDetail' => [fn ($c) => $c->getContractDetail($cups(), '2'), 'get-contract-detail'],
        'getConsumptionData' => [fn ($c) => $c->getConsumptionData($cups(), '2', 5, monthsAgo(), null, MeasurementType::Hourly), 'get-consumption-data'],
        'getMaxPower' => [fn ($c) => $c->getMaxPower($cups(), '2', monthsAgo()), 'get-max-power'],
        'getReactiveData' => [fn ($c) => $c->getReactiveData($cups(), '2', monthsAgo()), 'get-reactive-data'],
        'getContractDetailOf' => [fn ($c) => $c->getContractDetailOf($c->findSupply($cups())), 'get-contract-detail'],
        'getConsumptionDataOf' => [fn ($c) => $c->getConsumptionDataOf($c->findSupply($cups()), monthsAgo()), 'get-consumption-data'],
        'getMaxPowerOf' => [fn ($c) => $c->getMaxPowerOf($c->findSupply($cups()), monthsAgo()), 'get-max-power'],
        'getLatestConsumptionDataOf' => [fn ($c) => $c->getLatestConsumptionDataOf($c->findSupply($cups())), 'get-consumption-data'],
        'getLatestMaxPowerOf' => [fn ($c) => $c->getLatestMaxPowerOf($c->findSupply($cups())), 'get-max-power'],
        'getReactiveDataOf' => [fn ($c) => $c->getReactiveDataOf($c->findSupply($cups()), monthsAgo()), 'get-reactive-data'],
        'newAuthorization' => [fn ($c) => $c->newAuthorization($nif(), null, null, $cups()), 'new-authorization'],
        'cancelAuthorization' => [fn ($c) => $c->cancelAuthorization($nif()), 'cancel-authorization'],
        'listAuthorization' => [fn ($c) => $c->listAuthorization(), 'list-authorization'],
        'getGroups' => [fn ($c) => $c->getGroups(), 'get-groups'],
        'partnerUserList' => [fn ($c) => $c->partnerUserList(), 'partner-user-list'],
        'partnerDeleteUser' => [fn ($c) => $c->partnerDeleteUser($nif()), 'partner-delete-user'],
        'partnerAgreementDate' => [fn ($c) => $c->partnerAgreementDate(), 'partner-agreement-date'],
        'forHolder' => [fn ($c) => $c->forHolder($nif())->getSupplies(), 'get-supplies'],
        'checkLogin' => [fn ($c) => $c->checkLogin(), 'nikola-auth/tokens/login'],
    ];
}

/**
 * The calls of the client that send nothing: they look at the 24 hour ledger or at a range. [what to call, what it gives].
 *
 * @return array<string, array{Closure, mixed}>
 */
function offlineClientCalls(): array
{
    $cups = fn () => Cups::fromString(CUPS);
    $supply = fn ($c) => $c->findSupply($cups());
    $sentAt = fn () => new DateTimeImmutable('-1 hour');

    return [
        'assertServedRange' => [fn ($c) => $c->assertServedRange(monthsAgo(), monthsAgo(1)), null],
        'consumptionDataBlockedUntil' => [fn ($c) => $c->consumptionDataBlockedUntil($cups(), '2', 5, monthsAgo()), null],
        'consumptionDataBlockedUntilOf' => [fn ($c) => $c->consumptionDataBlockedUntilOf($supply($c), monthsAgo()), null],
        'maxPowerBlockedUntil' => [fn ($c) => $c->maxPowerBlockedUntil($cups(), '2', monthsAgo()), null],
        'maxPowerBlockedUntilOf' => [fn ($c) => $c->maxPowerBlockedUntilOf($supply($c), monthsAgo()), null],
        'reactiveDataBlockedUntil' => [fn ($c) => $c->reactiveDataBlockedUntil($cups(), '2', monthsAgo()), null],
        'reactiveDataBlockedUntilOf' => [fn ($c) => $c->reactiveDataBlockedUntilOf($supply($c), monthsAgo()), null],
        'rememberConsumptionData' => [fn ($c) => $c->rememberConsumptionData($sentAt(), $cups(), '2', 5, monthsAgo()), true],
        'rememberConsumptionDataOf' => [fn ($c) => $c->rememberConsumptionDataOf($sentAt(), $supply($c), monthsAgo(4)), true],
        'rememberMaxPower' => [fn ($c) => $c->rememberMaxPower($sentAt(), $cups(), '2', monthsAgo()), true],
        'rememberMaxPowerOf' => [fn ($c) => $c->rememberMaxPowerOf($sentAt(), $supply($c), monthsAgo(4)), true],
        'rememberReactiveData' => [fn ($c) => $c->rememberReactiveData($sentAt(), $cups(), '2', monthsAgo(5)), true],
        'rememberReactiveDataOf' => [fn ($c) => $c->rememberReactiveDataOf($sentAt(), $supply($c), monthsAgo(6)), true],
    ];
}

it('covers every public method of the client', function () {
    $methods = array_values(array_diff(get_class_methods(DatadisClient::class), ['__construct', 'fromArray']));

    expect([...array_keys(everyClientCall()), ...array_keys(offlineClientCalls())])->toEqualCanonicalizing($methods);
});

it('reaches every call of the client, read and write', function (string $call, string $through) {
    fakeEverything();
    [$run, $endpoint] = everyClientCall()[$call];
    $client = $through === 'facade' ? LaravelDatadisClient::getFacadeRoot() : app(DatadisClient::class);

    $run($client);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint));
})->with(array_keys(everyClientCall()))->with(['facade', 'injected']);

it('reaches the calls of the client that look at the ledger, on the cache of Laravel', function (string $call) {
    fakeEverything();
    [$run, $expected] = offlineClientCalls()[$call];

    $result = $run(app(DatadisClient::class));

    expect($result)->toBe($expected);
    expect(guardedRequests())->toBe(0);
})->with(array_keys(offlineClientCalls()));

it('reaches every public open data call', function (Closure $call, string $endpoint) {
    fakeEverything();

    $call(app(Manager::class)->publicApi());

    Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint));
})->with([
    'apiSearch' => [fn ($api) => $api->apiSearch(publicQuery()), 'api-search'],
    'apiSumSearch' => [fn ($api) => $api->apiSumSearch(publicQuery()), 'api-sum-search'],
    'apiSearchAuto' => [fn ($api) => $api->apiSearchAuto(selfConsumptionQuery()), 'api-search-auto'],
    'apiSumSearchAuto' => [fn ($api) => $api->apiSumSearchAuto(selfConsumptionQuery()), 'api-sum-search-auto'],
    'apiSearchAll' => [fn ($api) => iterator_to_array($api->apiSearchAll(publicQuery())), 'api-search'],
    'apiSearchAutoAll' => [fn ($api) => iterator_to_array($api->apiSearchAutoAll(selfConsumptionQuery())), 'api-search-auto'],
]);

function publicQuery(): PublicSearchQuery
{
    return new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);
}

function selfConsumptionQuery(): SelfConsumptionSearchQuery
{
    return new SelfConsumptionSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);
}

it('refuses every reactive energy call on API v1 before sending anything, and leaves the other calls alone', function () {
    config()->set('datadis-client.accounts.default.api_version', 'v1');
    fakeEverything();
    $client = app(DatadisClient::class);
    $supply = $client->findSupply(Cups::fromString(CUPS));

    foreach ([
        fn () => $client->getReactiveData(Cups::fromString(CUPS), '2', monthsAgo()),
        fn () => $client->getReactiveDataOf($supply, monthsAgo()),
        fn () => $client->reactiveDataBlockedUntil(Cups::fromString(CUPS), '2', monthsAgo()),
        fn () => $client->reactiveDataBlockedUntilOf($supply, monthsAgo()),
        fn () => $client->rememberReactiveData(new DateTimeImmutable('-1 hour'), Cups::fromString(CUPS), '2', monthsAgo()),
        fn () => $client->rememberReactiveDataOf(new DateTimeImmutable('-1 hour'), $supply, monthsAgo()),
    ] as $call) {
        expect($call)->toThrow(UnsupportedOperationException::class);
    }

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'reactive')))->toHaveCount(0);
    expect($client->getMaxPowerOf($supply, monthsAgo())->records)->toBe([]);   // maximum power is on v1
});
