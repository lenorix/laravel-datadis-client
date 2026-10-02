<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Psr\Log\LoggerInterface;

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

    app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

    // A new client, as another worker would have: only the cache remembers the first query.
    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))
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

it('exposes the public open data client, sharing the login', function () {
    fakeDatadis();
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
    expect(config('datadis-client'))->toHaveKeys(['cache', 'ledger']);

    $this->artisan('vendor:publish', ['--tag' => 'datadis-client-config', '--force' => true])->assertSuccessful();
    expect(config_path('datadis-client.php'))->toBeFile();
    unlink(config_path('datadis-client.php'));
});

it('keeps services.datadis on the account named default when another one is the default', function () {
    config()->set('datadis-client.default', 'other');
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'other-secret']);
    config()->set('services.datadis', ['username' => '00000000T', 'password' => 'services-secret']);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z' && $r['password'] === 'other-secret');
});

it('reports a refused repeat as a warning, not an error', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

    try {
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
    } catch (RepetitionWindowException $e) {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldNotReceive('error');
        app()->instance(LoggerInterface::class, $logger);

        app(ExceptionHandler::class)->report($e);
    }

    expect($e)->toBeInstanceOf(RepetitionWindowException::class);
});

it('turns the documented failure answers into the exceptions the testing skill promises', function (int $status, string $exception) {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/api-private/api/get-consumption-data*' => Http::response('', $status),
    ]);

    try {
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
        $caught = null;
    } catch (DatadisException $caught) {
    }

    expect($caught)->toBeInstanceOf($exception);
    expect($caught->requestSent)->toBeTrue();
})->with([
    '404 is no data' => [404, NoDataException::class],
    '503 is unavailable' => [503, ServiceUnavailableException::class],
]);

it('leaves the exception handler alone when report_level is null', function () {
    config()->set('datadis-client.report_level', null);
    app()->forgetInstance(ExceptionHandler::class);
    $handler = app(ExceptionHandler::class);

    $levels = (fn () => $this->levels)->call($handler);

    expect($levels)->not->toHaveKey(RepetitionWindowException::class);
});

it('sets the configured level on the exception handler', function () {
    config()->set('datadis-client.report_level', 'info');
    app()->forgetInstance(ExceptionHandler::class);
    $handler = app(ExceptionHandler::class);

    expect((fn () => $this->levels)->call($handler))->toHaveKey(RepetitionWindowException::class, 'info');
});

it('keeps the calls away from Laravel\'s Http events, recorders and fakes with the guzzle stack', function () {
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.accounts.default.base_url', 'https://datadis.invalid');
    config()->set('datadis-client.accounts.default.connect_timeout', 1);
    Event::fake([RequestSending::class, ConnectionFailed::class]);
    Http::fake();

    // .invalid never resolves: the call fails on the network, not on Laravel's stray request guard.
    $caught = null;
    try {
        app(DatadisClient::class)->getSupplies();
    } catch (DatadisException $e) {
        $caught = $e;
    }

    // With the Laravel stack the empty Http::fake() would have answered, so nothing would have failed.
    expect($caught)->toBeInstanceOf(DatadisException::class);
    expect($caught->getMessage())->not->toContain('StrayRequestException');

    Http::assertNothingSent();
    Event::assertNotDispatched(RequestSending::class);
});

it('refuses an unknown http stack', function () {
    config()->set('datadis-client.http.stack', 'curl');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'must be "laravel" or "guzzle"');
});

it('warns about a distributor that failed while listing supplies', function () {
    fakeDatadis();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/api-private/api/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => [
            ['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500', 'errorDescription' => 'distributor is down'],
        ]]),
    ]);

    $this->artisan('datadis:supplies')->expectsOutputToContain('distributor is down')->assertSuccessful();
});

it('completes a whole flow on the guzzle stack, decoding the answers, without touching Laravel\'s Http', function () {
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'text/plain'], fakeToken()),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['timeCurve' => [[
            'cups' => CUPS, 'date' => '2026/07/01', 'time' => '01:00', 'consumptionKWh' => 0.123, 'obtainMethod' => 'Real',
        ]], 'distributorError' => []])),
    ]);
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.http.options', ['handler' => HandlerStack::create($mock)]);
    Http::fake();

    $client = app(DatadisClient::class);
    $supply = $client->findSupply(Cups::fromString(CUPS));
    $result = $client->getConsumptionDataOf($supply, monthsAgo());

    expect($supply?->distributorCode)->toBe('2');
    expect($result->records)->toHaveCount(1);
    expect($result->records[0]->consumptionKWh)->toBe('0.123');
    expect($mock->count())->toBe(0);   // login, supplies and consumption all answered by Guzzle
    Http::assertNothingSent();
});
