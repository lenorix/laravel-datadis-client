<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\LaravelDatadisClientServiceProvider;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

it('binds a client built from the configuration', function () {
    expect(app(DatadisClient::class))->toBeInstanceOf(DatadisClient::class);
});

it('sends every call through Laravel\'s Http so it can be faked and asserted', function () {
    FakesDatadis::fake();

    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));

    expect($supply?->cups)->toBe(CUPS);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies'));
});

it('proxies the facade to the default account', function () {
    FakesDatadis::fake();

    expect(LaravelDatadisClient::getSupplies()->records)->toHaveCount(1);
    expect(LaravelDatadisClient::account())->toBeInstanceOf(DatadisClient::class);
});

it('keeps working after Http::fake() replaces the HTTP factory', function () {
    LaravelDatadisClient::account(); // resolves the facade root before the fake
    FakesDatadis::fake();

    expect(LaravelDatadisClient::getSupplies()->records)->toHaveCount(1);
});

it('exposes the public open data client, sharing the login', function () {
    FakesDatadis::fake();
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);

    app(PublicApiClient::class)->apiSearch($query);
    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api-public/api-search')))->toHaveCount(1);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);
    expect(app(Manager::class)->publicApi())->toBeInstanceOf(PublicApiClient::class);
    expect(fn () => app(Manager::class)->publicApi('missing'))->toThrow(InvalidArgumentException::class);
});

it('refuses real requests nothing faked', function () {
    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(DatadisException::class, 'StrayRequestException');
});

it('publishes the config under the package tag and ships sensible defaults', function () {
    expect(config('datadis-client.default'))->toBe('default');
    expect(config('datadis-client.accounts.default.api_version'))->toBe('v2');
    expect(config('datadis-client.accounts.default.timezone'))->toBe('Europe/Madrid');
    expect(config('datadis-client.accounts.default.timeout'))->toBe(120);
    expect(config('datadis-client'))->toHaveKey('ledger')->not->toHaveKey('cache');

    // Nothing is copied: a published file in Testbench's shared skeleton would race with parallel tests.
    $published = ServiceProvider::pathsToPublish(LaravelDatadisClientServiceProvider::class, 'datadis-client-config');

    expect(array_keys($published))->toHaveCount(1);
    expect(array_key_first($published))->toBeFile();
    expect(basename((string) array_key_first($published)))->toBe('datadis-client.php');
    expect(basename((string) reset($published)))->toBe('datadis-client.php');
});

it('offers the operations that change data through the facade and the injected client', function (Closure $client) {
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(FakesDatadis::token(), 200, ['Content-Type' => 'text/plain']),
        '*/new-authorization*' => Http::response('Authorization created', 200, ['Content-Type' => 'text/plain']),
        '*/cancel-authorization*' => Http::response('Authorization cancelled', 200, ['Content-Type' => 'text/plain']),
        '*/partner-delete-user*' => Http::response('User unlinked', 200, ['Content-Type' => 'text/plain']),
    ]);
    $nif = Nif::fromString('12345678Z');

    expect($client()->newAuthorization($nif, null, null, Cups::fromString(CUPS)))->toBe('Authorization created');
    expect($client()->cancelAuthorization($nif))->toBe('Authorization cancelled');
    expect($client()->partnerDeleteUser($nif))->toBe('User unlinked');

    foreach (['new-authorization', 'cancel-authorization', 'partner-delete-user'] as $endpoint) {
        Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint));
    }
})->with([
    'facade (forwarded call)' => [fn () => LaravelDatadisClient::getFacadeRoot()],
    'injected' => [fn () => app(DatadisClient::class)],
]);
