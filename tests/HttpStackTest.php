<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

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
    app()['env'] = $environment;
    fakeDatadis();
    config()->set('datadis-client.http.stack', null);   // after the fake, which forces Laravel's stack: this is what is under test

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

it('fails a login that cannot connect as a transport error, before any data is asked', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('connection refused')]);

    expect(fn () => app(DatadisClient::class)->getSupplies())->toThrow(TransportException::class);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(0);
});

it('refuses the guzzle stack in the test environment unless the test brought a mock handler', function () {
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.http.options', []);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'would reach Datadis');
});

it('passes extra Guzzle options to every call on the laravel stack', function () {
    config()->set('datadis-client.http.options', ['headers' => ['X-Trace' => 'abc']]);
    fakeDatadis();

    app(DatadisClient::class)->getSupplies();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && $r->hasHeader('X-Trace', 'abc'));
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
