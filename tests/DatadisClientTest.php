<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\LaravelDatadisClientServiceProvider;
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

it('keeps the token in the default cache store of Laravel', function () {
    config()->set('cache.stores.other', ['driver' => 'array']);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);
    // Another default store never saw the token: a client on it logs in again.
    config()->set('cache.default', 'other');
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
    expect(config('datadis-client'))->toHaveKey('ledger')->not->toHaveKey('cache');

    // Nothing is copied: a published file in Testbench's shared skeleton would race with parallel tests.
    $published = ServiceProvider::pathsToPublish(LaravelDatadisClientServiceProvider::class, 'datadis-client-config');

    expect(array_keys($published))->toHaveCount(1);
    expect(array_key_first($published))->toBeFile();
    expect(basename((string) array_key_first($published)))->toBe('datadis-client.php');
    expect(basename((string) reset($published)))->toBe('datadis-client.php');
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

it('fails a call on the guzzle stack as a transport error, never through Laravel\'s Http', function () {
    $mock = new MockHandler([
        new ConnectException('connection refused', new GuzzleHttp\Psr7\Request('POST', 'https://datadis.es')),
    ]);
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.http.options', ['handler' => HandlerStack::create($mock)]);
    Http::fake();

    // With the Laravel stack the empty Http::fake() would have answered, so nothing would have failed.
    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
    expect($mock->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses an unknown http stack', function () {
    config()->set('datadis-client.http.stack', 'curl');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'must be "laravel" or "guzzle"');
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

it('never reaches the network on the guzzle stack unless a test queues an answer', function () {
    config()->set('datadis-client.http.stack', 'guzzle');

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
});

it('sends the calls through plain Guzzle unless the test environment asks for Laravel\'s stack', function (string $environment, bool $laravelStack) {
    config()->set('datadis-client.http.stack', null);
    app()['env'] = $environment;
    fakeDatadis();

    if ($laravelStack) {
        expect(app(DatadisClient::class)->getSupplies()->records)->toHaveCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies'));
    } else {
        // The empty Guzzle mock of the test setup answers nothing, and Http::fake() is not consulted.
        expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
        Http::assertNothingSent();
    }
})->with([
    'testing' => ['testing', true],
    'production' => ['production', false],
    'local' => ['local', false],
]);

it('treats an empty stack as unset', function () {
    config()->set('datadis-client.http.stack', '');
    app()['env'] = 'production';

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
});

it('offers the operations that change data through the facade and the injected client', function (Closure $client) {
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
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

it('fails a login that cannot connect as a transport error, before any data is asked', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('connection refused')]);

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(0);
});

it('counts a consumption query lost in transit as used, so it is not repeated', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-consumption-data*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('timed out');
        },
    ]);

    try {
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
    } catch (TransportException $e) {
    }

    expect($e)->toBeInstanceOf(TransportException::class);
    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))->toThrow(RepetitionWindowException::class);
    expect($attempts)->toBe(1);
});

it('logs in again when the token it was given has already expired', function () {
    $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $expired = $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'a', 'iat' => time() - 200000, 'exp' => time() - 100000])).'.sig';
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::sequence()->push($expired, 200, ['Content-Type' => 'text/plain'])->push(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []]),
    ]);

    app(DatadisClient::class)->getSupplies();
    app(DatadisClient::class)->getSupplies();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(2);
});

it('refuses a guarded query without sending it when the cache store cannot be used', function () {
    Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            throw new RuntimeException('cache is down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache is down');
        }

        public function add($key, $value, $seconds)
        {
            throw new RuntimeException('cache is down');
        }
    }));
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('cache.default', 'broken');

    expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))->toThrow(LedgerUnavailableException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(0);
});

it('lists the supplies of a named account, logging in as that account', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'other-secret']);
    fakeDatadis();

    $this->artisan('datadis:supplies --account=other')->expectsOutputToContain(CUPS)->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z');
});

it('fails the command cleanly for a holder that is not a valid NIF', function (string $holder) {
    fakeDatadis();

    $this->artisan("datadis:supplies --holder={$holder}")->assertFailed();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(0);
})->with(['not a nif' => 'nope', 'wrong control letter' => '12345678A', 'too short' => '1234']);

it('refuses a report level the logger would reject, without breaking error reporting', function (mixed $level) {
    $file = sys_get_temp_dir().'/datadis-report-'.bin2hex(random_bytes(4)).'.log';
    config()->set('logging.default', 'single');
    config()->set('logging.channels.single', ['driver' => 'single', 'path' => $file]);
    config()->set('datadis-client.report_level', $level);
    app()->forgetInstance(ExceptionHandler::class);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'report_level');
    expect(fn () => app(Manager::class)->publicApi())->toThrow(ConfigurationException::class, 'report_level');

    // The handler still reports: the bad level is ignored there.
    app(ExceptionHandler::class)->report(new RepetitionWindowException('repeat'));
    expect(is_file($file) ? file_get_contents($file) : '')->toContain('repeat');
    @unlink($file);
})->with(['typo' => 'warn', 'number' => 3, 'array' => [['warning']]]);

it('takes the report level in any case', function () {
    config()->set('datadis-client.report_level', ' WARNING ');

    expect(app(Manager::class)->reportLevel())->toBe('warning');
    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('refuses the guzzle stack in the test environment unless the test brought a mock handler', function () {
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.http.options', []);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'would reach Datadis');
});

it('retries a harmless read after a gateway error, and the login too', function () {
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::sequence()
            ->push('', 503)
            ->push(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::sequence()
            ->push('', 502)
            ->push('', 504)
            ->push(['supplies' => [], 'distributorError' => []]),
    ]);

    expect(app(DatadisClient::class)->getSupplies()->records)->toBe([]);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(2);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(3);
});

it('gives up after the configured retries', function () {
    config()->set('datadis-client.http.retries.max', 1);
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response('', 503),
    ]);

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(ServiceUnavailableException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(2);
});

it('never retries a data query nor a call that changes data', function (string $endpoint, Closure $call) {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        "*/{$endpoint}*" => Http::response('', 503),
    ]);

    expect(fn () => $call(app(DatadisClient::class), $supply))->toThrow(ServiceUnavailableException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), $endpoint)))->toHaveCount(1);
})->with([
    'consumption' => ['get-consumption-data', fn ($c, $supply) => $c->getConsumptionDataOf($supply, monthsAgo())],
    'maximum power' => ['get-max-power', fn ($c, $supply) => $c->getMaxPowerOf($supply, monthsAgo())],
    'new authorization' => ['new-authorization', fn ($c) => $c->newAuthorization(Nif::fromString('12345678Z'))],
    'cancel authorization' => ['cancel-authorization', fn ($c) => $c->cancelAuthorization(Nif::fromString('12345678Z'))],
    'unlink a partner user' => ['partner-delete-user', fn ($c) => $c->partnerDeleteUser(Nif::fromString('12345678Z'))],
]);

it('does not retry when retries are turned off', function () {
    config()->set('datadis-client.http.retries.max', 0);
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response('', 503),
    ]);

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(ServiceUnavailableException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(1);
});

it('refuses retry settings that cannot work', function (array $retries, string $message) {
    config()->set('datadis-client.http.retries', $retries);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, $message);
})->with([
    'too many' => [['max' => 11], 'between 0 and 10'],
    'negative' => [['max' => -1], 'between 0 and 10'],
    'not a number' => [['max' => 'many'], 'must be a whole number'],
    'a float' => [['base_delay_ms' => 1.5], 'must be a whole number'],
    'zero delay' => [['max' => 2, 'base_delay_ms' => 0], 'delays must be positive'],
    'max below base' => [['max' => 2, 'base_delay_ms' => 100, 'max_delay_ms' => 10], 'not below'],
]);

it('takes the retry settings as text from the environment', function () {
    config()->set('datadis-client.http.retries', ['max' => '3', 'base_delay_ms' => ' 5 ', 'max_delay_ms' => '50']);

    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('uses the default retries when the setting is not an array', function () {
    config()->set('datadis-client.http.retries', null);

    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('passes extra Guzzle options to every call on the laravel stack', function () {
    config()->set('datadis-client.http.options', ['headers' => ['X-Trace' => 'abc']]);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && $r->hasHeader('X-Trace', 'abc'));
});

it('never lets the token or its key reach Laravel\'s cache events', function () {
    $token = fakeToken();
    $events = [];
    Event::listen('Illuminate\Cache\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = [$name, json_encode(array_map(fn ($event) => get_object_vars($event), $payload), JSON_PARTIAL_OUTPUT_ON_ERROR)];
    });
    Http::fake([
        '*/nikola-auth/tokens/login' => Http::response($token, 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []]),
    ]);

    app(DatadisClient::class)->getSupplies();   // writes the token
    app(DatadisClient::class)->getSupplies();   // reads it back

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'login')))->toHaveCount(1);   // the token was cached and reused
    expect($events)->toBe([]);
});

it('takes a cache repository that is not Laravel\'s as it is', function () {
    $factory = Mockery::mock(Illuminate\Contracts\Cache\Factory::class);
    $factory->shouldReceive('store')->andReturn(Mockery::mock(Repository::class));
    app()->instance(Illuminate\Contracts\Cache\Factory::class, $factory);

    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('refuses a guard key that is not a text, instead of falling back to the application key', function (mixed $key) {
    config()->set('datadis-client.ledger.key', $key);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'must be a text or null');
})->with(['int' => [123], 'array' => [['secret']], 'bool' => [true]]);

it('keys the guard with a secret derived from the application key, not the key itself', function () {
    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', str_repeat('a', 32));
    $secret = (fn () => $this->ledgerKey())->call(app(Manager::class));

    expect(strlen($secret))->toBe(32);
    expect($secret)->not->toBe(str_repeat('a', 32));
    expect($secret)->toBe(hash_hmac('sha256', 'laravel-datadis-client: 24 hour guard', str_repeat('a', 32), true));

    config()->set('datadis-client.ledger.key', 'my-own-guard-secret');
    expect((fn () => $this->ledgerKey())->call(app(Manager::class)))->toBe('my-own-guard-secret');
});

it('refuses an application key too short to key the guard', function () {
    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', 'short');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'too short');
});

it('accepts every level of the logger, in any case, and none that is not one', function () {
    foreach (Manager::REPORT_LEVELS as $level) {
        config()->set('datadis-client.report_level', strtoupper($level));
        expect(app(Manager::class)->reportLevel())->toBe($level);
    }

    expect(Manager::REPORT_LEVELS)->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
});

it('has no report level to set when it is null or empty', function (mixed $level) {
    config()->set('datadis-client.report_level', $level);

    expect(app(Manager::class)->reportLevel())->toBeNull();
})->with([null, '']);

it('refuses a default cache store that remembers nothing, instead of refusing every query as already sent', function () {
    config()->set('cache.stores.nothing', ['driver' => 'null']);
    config()->set('cache.default', 'nothing');

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'remembers nothing');
});

it('refuses the Guzzle debug option, which would print the login password', function (mixed $debug, bool $refused) {
    config()->set('datadis-client.http.options.debug', $debug);

    if ($refused) {
        expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'would print the login request');
    } else {
        expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
    }
})->with([
    'true' => [true, true],
    'a stream' => [STDERR, true],
    'false' => [false, false],
]);

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
