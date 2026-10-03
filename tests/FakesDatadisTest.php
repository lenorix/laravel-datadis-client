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

it('answers each endpoint with the shape the real one has, which is the default of every test', function () {
    FakesDatadis::fake();
    $get = fn (string $path) => Http::get('https://datadis.es'.$path);
    $empty = fn (string $key) => [$key => [], 'distributorError' => []];

    expect($get('/api-private/api/get-supplies-v2')->json())->toBe(['supplies' => [[
        'cups' => FakesDatadis::CUPS, 'distributor' => 'A DISTRIBUTOR', 'pointType' => 5, 'distributorCode' => '2',
        'validDateFrom' => '2020/01/01', 'validDateTo' => '',
    ]], 'distributorError' => []]);
    expect($get('/api-private/api/get-distributors-with-supplies-v2')->json())->toBe(['distributorError' => []]);
    expect($get('/api-private/api/get-contract-detail-v2')->json())->toBe($empty('contract'));
    expect($get('/api-private/api/get-consumption-data-v2')->json())->toBe($empty('timeCurve'));
    expect($get('/api-private/api/get-max-power-v2')->json())->toBe($empty('maxPower'));
    expect($get('/api-private/api/get-reactive-data-v2')->json())->toBe($empty('reactiveEnergy'));
    expect($get('/api-private/api/list-authorization')->json())->toBe($empty('authorizations'));
    expect($get('/api-private/api/get-groups')->json())->toBe($empty('groups'));
    expect($get('/api-private/api/partner-user-list')->json())->toBe($empty('users'));
    expect($get('/api-private/api/partner-agreement-date')->json())->toBe(['partnerAgreementDate' => null]);
    expect($get('/api-public/api-search')->json())->toBe([]);

    foreach (['new-authorization' => 'created', 'cancel-authorization' => 'cancelled', 'partner-delete-user' => 'unlinked'] as $path => $answer) {
        $response = $get('/api-private/api/'.$path);
        expect($response->status())->toBe(200);
        expect($response->body())->toBe($answer);
        expect($response->header('Content-Type'))->toContain('text/plain');
    }
    $login = $get('/nikola-auth/tokens/login');
    expect($login->body())->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.signature$/');
    expect($login->status())->toBe(200);
    expect($login->header('Content-Type'))->toContain('text/plain');
});

it('makes a token shaped like the real one: unsigned header, three claims, no padding', function () {
    [$header, $payload, $signature] = explode('.', FakesDatadis::token());
    $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);

    expect($decode($header))->toBe(['alg' => 'HS512']);
    expect(array_keys($decode($payload)))->toBe(['sub', 'iat', 'exp']);
    expect($decode($payload)['sub'])->toBe('account');
    expect($signature)->toBe('signature');
    expect(FakesDatadis::token())->not->toContain('=');
});

it('fakes the calls whatever the environment, by forcing the package onto Laravel\'s HTTP client', function () {
    // Outside the testing environment the package sends through plain Guzzle, which Http::fake() never sees.
    app()['env'] = 'production';
    config()->set('datadis-client.http.stack', 'guzzle');   // even a stray setting for Guzzle

    FakesDatadis::fake();

    expect(app(DatadisClient::class)->getSupplies()->records)->toHaveCount(1);
});
