<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

it('builds clients for named accounts', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'x']);

    expect(app(Manager::class)->account('other'))->toBeInstanceOf(DatadisClient::class);
    expect(fn () => app(Manager::class)->account('missing'))->toThrow(InvalidArgumentException::class);
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

it('sends the holder on supplies, contract, consumption and power calls, from the facade and the injected client', function (Closure $client) {
    fakeDatadis();
    $holder = $client()->forHolder(Nif::fromString('12345678Z'));
    $supply = $holder->findSupply(Cups::fromString(CUPS));

    $holder->getContractDetailOf($supply);
    $holder->getConsumptionDataOf($supply, monthsAgo());
    $holder->getMaxPowerOf($supply, monthsAgo());

    foreach (['get-supplies', 'get-contract-detail', 'get-consumption-data', 'get-max-power'] as $endpoint) {
        Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint) && str_contains($r->url(), 'authorizedNif=12345678Z'));
    }
})->with([
    'facade' => [fn () => LaravelDatadisClient::account()],
    'injected' => [fn () => app(DatadisClient::class)],
]);

it('does not send authorizedNif for the account\'s own supplies', function () {
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && ! str_contains($r->url(), 'authorizedNif'));
});

it('keeps services.datadis on the account named default when another one is the default', function () {
    config()->set('datadis-client.default', 'other');
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'other-secret']);
    config()->set('services.datadis', ['username' => '00000000T', 'password' => 'services-secret']);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z' && $r['password'] === 'other-secret');
});

it('trims the default account name', function () {
    config()->set('datadis-client.accounts.work', ['username' => '12345678Z', 'password' => 'x']);
    config()->set('datadis-client.default', ' work ');
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z');
});

it('resolves an account whose name has a dot, and a name that only looks like a path into another one', function () {
    config()->set('datadis-client.accounts', [
        'default' => ['username' => '00000000T', 'password' => 'x'],
        'tenant.east' => ['username' => '12345678Z', 'password' => 'y'],
    ]);
    fakeDatadis();

    app(Manager::class)->account('tenant.east')->getSupplies();

    expect(array_column(logins(), 'username'))->toBe(['12345678Z']);
    // `default.username` is a path into the default account, not an account.
    expect(fn () => app(Manager::class)->account('default.username'))->toThrow(InvalidArgumentException::class);
});

it('reaches an account with a dot in its name from the public API and the commands too', function () {
    config()->set('datadis-client.accounts', [
        'default' => ['username' => '00000000T', 'password' => 'x'],
        'tenant.east' => ['username' => '12345678Z', 'password' => 'y'],
    ]);
    fakeEverything();

    expect(app(Manager::class)->publicApi('tenant.east'))->toBeInstanceOf(PublicApiClient::class);

    $this->artisan('datadis:supplies --account=tenant.east')->assertExitCode(0);
    expect(array_column(logins(), 'username'))->toBe(['12345678Z']);
});
