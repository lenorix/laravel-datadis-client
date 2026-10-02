<?php

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
