<?php

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

it('sends nothing when it imports', function () {
    fakeEverything();
    Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, monthsAgo(2), at: new DateTimeImmutable('-1 hour'));
    Http::assertNothingSent();
});

it('imports with a wrong http setting, which has nothing to do with it', function () {
    config()->set('datadis-client.http.retries', ['max' => 'many']);
    config()->set('datadis-client.http.options.debug', true);

    expect(Datadis::rememberConsumption(Cups::fromString(CUPS), '2', 5, monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
});

it('still refuses to import for an account that is not configured or whose settings are wrong', function () {
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'))->toThrow(InvalidArgumentException::class, 'not configured');

    config()->set('datadis-client.accounts.broken', ['username' => 'nope', 'password' => 'x']);
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'broken'))->toThrow(ConfigurationException::class);
});

it('names the account that is not configured, the default one included', function () {
    config()->set('datadis-client.default', 'ghost');

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), account: 'missing'))->toThrow(InvalidArgumentException::class, '[missing]');
    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2)))->toThrow(InvalidArgumentException::class, '[ghost]');
});

it('refuses to import when the report level is wrong, as every other call does', function () {
    config()->set('datadis-client.report_level', 'bogus');

    expect(fn () => Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))
        ->toThrow(ConfigurationException::class, 'report_level');
});

it('imports on a cache repository that is not Laravel\'s, without a lock', function () {
    $held = [];
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('get')->andReturnUsing(fn (string $key) => $held[$key] ?? null);
    $repository->shouldReceive('add')->andReturnUsing(function (string $key, $value) use (&$held) {
        $held[$key] = $value;

        return true;
    });
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('store')->andReturn($repository);
    app()->instance(Factory::class, $factory);

    expect(Datadis::rememberMaxPower(Cups::fromString(CUPS), '2', monthsAgo(2), at: new DateTimeImmutable('-1 hour')))->toBeTrue();
    expect($held)->toHaveCount(1);
});
