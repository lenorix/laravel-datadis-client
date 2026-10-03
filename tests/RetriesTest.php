<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

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
