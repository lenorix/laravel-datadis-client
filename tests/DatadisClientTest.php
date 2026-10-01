<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;

const CUPS = 'ES0000000000000000AA0A';

function fakeToken(): string
{
    $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    return $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'a', 'iat' => time(), 'exp' => time() + 86400])).'.sig';
}

function fakeDatadis(): void
{
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/api-private/api/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2',
            'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'postalCode' => '28001',
        ]], 'distributorError' => []]),
        '*/api-private/api/get-consumption-data*' => Http::response(['timeCurve' => [], 'distributorError' => []]),
    ]);
}

it('binds a client built from the configuration', function () {
    expect(app(DatadisClient::class))->toBeInstanceOf(DatadisClient::class);
});

it('sends every call through Laravel\'s Http so it can be faked and asserted', function () {
    fakeDatadis();

    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));

    expect($supply?->cups)->toBe(CUPS);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies'));
});

it('proxies the facade to the default account', function () {
    fakeDatadis();

    expect(LaravelDatadisClient::getSupplies()->records)->toHaveCount(1);
    expect(LaravelDatadisClient::account())->toBeInstanceOf(DatadisClient::class);
});

it('keeps working after Http::fake() replaces the HTTP factory', function () {
    LaravelDatadisClient::account(); // resolves the facade root before the fake
    fakeDatadis();

    expect(LaravelDatadisClient::getSupplies()->records)->toHaveCount(1);
});

it('refuses a repeated query across clients through the shared cache', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));

    app(DatadisClient::class)->getConsumptionDataOf($supply, Month::of(2026, 7));

    // A new client, as another worker would have: only the cache remembers the first query.
    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, Month::of(2026, 7)))
        ->toThrow(RepetitionWindowException::class);
    Http::assertSentCount(3); // login, supplies, one consumption
});

it('shares the login token between clients through the cache', function () {
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();
    app(DatadisClient::class)->getSupplies();

    $logins = Http::recorded(fn (Request $r) => str_contains($r->url(), 'login'));
    expect($logins)->toHaveCount(1);
});

it('uses the configured cache store', function () {
    config()->set('cache.stores.datadis', ['driver' => 'array']);
    config()->set('datadis-client.cache.store', 'datadis');
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);
    // The default store never saw the token: a client on it logs in again.
    config()->set('datadis-client.cache.store', null);
    app(DatadisClient::class)->getSupplies();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(2);
});

it('builds clients for named accounts', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'x']);

    expect(app(Manager::class)->account('other'))->toBeInstanceOf(DatadisClient::class);
    expect(fn () => app(Manager::class)->account('missing'))->toThrow(InvalidArgumentException::class);
});

it('derives the guard secret from the app key, or takes its own', function () {
    config()->set('app.key', 'base64:'.base64_encode('short'));
    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class);

    config()->set('datadis-client.ledger.key', str_repeat('z', 32));
    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('lists supplies with the artisan command', function () {
    fakeDatadis();

    $this->artisan('datadis:supplies')->expectsOutputToContain(CUPS)->assertSuccessful();
});

it('fails the command cleanly for an unknown account', function () {
    $this->artisan('datadis:supplies --account=nope')->assertFailed();
});

it('sends the holder as authorizedNif from the command', function () {
    fakeDatadis();

    $this->artisan('datadis:supplies --holder=12345678Z')->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && str_contains($r->url(), 'authorizedNif=12345678Z'));
});

it('adds to the cache only when the key is absent', function () {
    $store = new LaravelAtomicStore(app('cache')->store());

    expect($store->add('k', 1, 60))->toBeTrue();
    expect($store->add('k', 2, 60))->toBeFalse();
});

it('reads the credentials of the default account from services.datadis first', function () {
    config()->set('datadis-client.accounts.default', ['username' => null, 'password' => null, 'timeout' => 30]);
    config()->set('services.datadis', ['username' => '12345678Z', 'password' => 'from-services']);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z' && $r['password'] === 'from-services');
});

it('does not apply services.datadis to other accounts', function () {
    config()->set('services.datadis', ['username' => '12345678Z', 'password' => 'x']);

    expect(fn () => app(Manager::class)->account('other'))->toThrow(InvalidArgumentException::class);
});
