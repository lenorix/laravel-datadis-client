<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
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
        'getReactiveDataOf' => [fn ($c) => $c->getReactiveDataOf($c->findSupply($cups()), monthsAgo()), 'get-reactive-data'],
        'newAuthorization' => [fn ($c) => $c->newAuthorization($nif(), null, null, $cups()), 'new-authorization'],
        'cancelAuthorization' => [fn ($c) => $c->cancelAuthorization($nif()), 'cancel-authorization'],
        'listAuthorization' => [fn ($c) => $c->listAuthorization(), 'list-authorization'],
        'getGroups' => [fn ($c) => $c->getGroups(), 'get-groups'],
        'partnerUserList' => [fn ($c) => $c->partnerUserList(), 'partner-user-list'],
        'partnerDeleteUser' => [fn ($c) => $c->partnerDeleteUser($nif()), 'partner-delete-user'],
        'partnerAgreementDate' => [fn ($c) => $c->partnerAgreementDate(), 'partner-agreement-date'],
        'forHolder' => [fn ($c) => $c->forHolder($nif())->getSupplies(), 'get-supplies'],
    ];
}

function fakeEverything(): void
{
    $list = fn (string $key) => Http::response([$key => [], 'distributorError' => []]);
    $text = fn (string $body) => Http::response($body, 200, ['Content-Type' => 'text/plain']);

    Http::fake([
        '*/nikola-auth/tokens/login' => $text(fakeToken()),
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-distributors-with-supplies*' => Http::response(['distributorError' => []]),
        '*/get-contract-detail*' => $list('contract'),
        '*/get-consumption-data*' => $list('timeCurve'),
        '*/get-max-power*' => $list('maxPower'),
        '*/get-reactive-data*' => Http::response(['reactiveEnergy' => [], 'distributorError' => []]),
        '*/new-authorization*' => $text('created'),
        '*/cancel-authorization*' => $text('cancelled'),
        '*/list-authorization*' => $list('authorizations'),
        '*/get-groups*' => $list('groups'),
        '*/partner-user-list*' => $list('users'),
        '*/partner-delete-user*' => $text('unlinked'),
        '*/partner-agreement-date*' => Http::response(['partnerAgreementDate' => null]),
        '*/api-public/api-*' => Http::response([]),
    ]);
}

it('covers every public method of the client', function () {
    $methods = array_values(array_diff(get_class_methods(DatadisClient::class), ['__construct', 'fromArray']));

    expect(array_keys(everyClientCall()))->toEqualCanonicalizing($methods);
});

it('reaches every call of the client, read and write', function (string $call, string $through) {
    fakeEverything();
    [$run, $endpoint] = everyClientCall()[$call];
    $client = $through === 'facade' ? LaravelDatadisClient::getFacadeRoot() : app(DatadisClient::class);

    $run($client);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint));
})->with(array_keys(everyClientCall()))->with(['facade', 'injected']);

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
