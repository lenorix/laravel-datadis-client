<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

it('answers every call of the client with a fake that is the same on every run', function (Closure $call, string $endpoint) {
    FakesDatadis::fake();

    $call(app(DatadisClient::class));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint));
})->with([
    'supplies' => [fn ($c) => $c->getSupplies(), 'get-supplies'],
    'distributors' => [fn ($c) => $c->getDistributorsWithSupplies(), 'get-distributors-with-supplies'],
    'contract' => [fn ($c) => $c->getContractDetailOf($c->findSupply(Cups::fromString(FakesDatadis::CUPS))), 'get-contract-detail'],
    'consumption' => [fn ($c) => $c->getConsumptionDataOf($c->findSupply(Cups::fromString(FakesDatadis::CUPS)), monthsAgo()), 'get-consumption-data'],
    'maximum power' => [fn ($c) => $c->getMaxPowerOf($c->findSupply(Cups::fromString(FakesDatadis::CUPS)), monthsAgo()), 'get-max-power'],
    'reactive' => [fn ($c) => $c->getReactiveDataOf($c->findSupply(Cups::fromString(FakesDatadis::CUPS)), monthsAgo()), 'get-reactive-data'],
    'new authorization' => [fn ($c) => $c->newAuthorization(Nif::fromString('12345678Z')), 'new-authorization'],
    'cancel authorization' => [fn ($c) => $c->cancelAuthorization(Nif::fromString('12345678Z')), 'cancel-authorization'],
    'list authorization' => [fn ($c) => $c->listAuthorization(), 'list-authorization'],
    'groups' => [fn ($c) => $c->getGroups(), 'get-groups'],
    'partner users' => [fn ($c) => $c->partnerUserList(), 'partner-user-list'],
    'partner delete' => [fn ($c) => $c->partnerDeleteUser(Nif::fromString('12345678Z')), 'partner-delete-user'],
    'partner agreement' => [fn ($c) => $c->partnerAgreementDate(), 'partner-agreement-date'],
]);

it('puts your answers before the defaults, so they win', function () {
    FakesDatadis::fake(['*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []])]);

    expect(app(DatadisClient::class)->getSupplies()->records)->toBe([]);
});

it('does not accumulate: a second fake replaces the first, where Http::fake() would keep the first', function () {
    FakesDatadis::fake(['*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []])]);
    FakesDatadis::fake();

    expect(app(DatadisClient::class)->getSupplies()->records)->toHaveCount(1);
});

it('changes the fields of the default supply', function () {
    FakesDatadis::fake(supply: ['validDateFrom' => '2026/10/01', 'pointType' => 3]);

    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(FakesDatadis::CUPS));

    expect($supply->validDateFrom->format('Y-m-d'))->toBe('2026-10-01');
    expect($supply->pointType)->toBe(3);
});

it('fails on a URL nobody answers, instead of reaching Datadis', function () {
    FakesDatadis::fake();

    expect(fn () => Http::get('https://datadis.example/unknown'))->toThrow(StrayRequestException::class);
});

it('gives a token of the application time, a day long', function () {
    $this->travelTo(Carbon::parse('2026-07-10 04:00', 'Europe/Madrid'));
    [, $payload] = explode('.', FakesDatadis::token());
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    expect($claims['iat'])->toBe(now()->timestamp);
    expect($claims['exp'] - $claims['iat'])->toBe(86400);
});
