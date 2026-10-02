<?php

use Eris\Generators;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

it('only ever fails with the documented exceptions, whatever account name it is given', function () {
    $this->limitTo(iterations() * 4)->forAll(Generators::string())->then(function (string $name) {
        try {
            app(Manager::class)->account($name);
            app(Manager::class)->publicApi($name);
        } catch (InvalidArgumentException|ConfigurationException) {
            // documented
        }
        expect(true)->toBeTrue();
    });
});

it('only ever fails with ConfigurationException for any application key', function () {
    $this->limitTo(iterations() * 4)->forAll(Generators::string())->then(function (string $key) {
        config()->set('datadis-client.ledger.key', null);
        config()->set('app.key', $key);

        try {
            app(Manager::class)->account();
        } catch (ConfigurationException) {
            // documented
        }
        expect(true)->toBeTrue();
    });
});

it('lets services.datadis win however a key is spelled, and ignores blank values', function () {
    $this->limitTo(iterations())->forAll(
        Generators::elements('api_version', 'api-version'),
        Generators::elements('api_version', 'api-version'),
        Generators::elements('v2', ' v2 '),
        Generators::elements('', '  ', null),
    )->then(function (string $accountKey, string $servicesKey, string $version, ?string $blank) {
        freshHttp();
        config()->set('datadis-client.accounts.default', ['username' => '00000000T', 'password' => 'x', $accountKey => 'v1']);
        config()->set('services.datadis', [$servicesKey => $version, 'timeout' => $blank, 'username' => $blank]);

        app(DatadisClient::class)->getSupplies();

        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), 'get-supplies-v2'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '00000000T');
    });
});

it('shares one guard between account names that log in as the same Datadis user, and keeps different users apart', function () {
    $this->limitTo(iterations())->forAll(Generators::bool(), Generators::elements('00000000T', '12345678Z'))->then(function (bool $sameUser, string $otherUser) {
        freshHttp();
        config()->set('datadis-client.accounts.copy', ['username' => $sameUser ? '00000000t' : $otherUser, 'password' => 'x']);
        $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
        $copy = app(Manager::class)->account('copy');
        $shared = $sameUser || $otherUser === '00000000T';

        try {
            $copy->getConsumptionDataOf($supply, monthsAgo());
            $refused = false;
        } catch (RepetitionWindowException) {
            $refused = true;
        }

        expect($refused)->toBe($shared);
    });
});
