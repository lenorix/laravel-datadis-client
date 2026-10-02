<?php

use Eris\Generators;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

it('resolves exactly the configured accounts, as the user each one logs in with', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(1, 99999999),
        Generators::choose(1, 99999999),
        Generators::oneOf(
            Generators::elements('default', 'a', 'b'),
            Generators::map(fn (string $s) => 'x'.$s, Generators::string()),
            Generators::elements('', '.', 'accounts', 'default.username', 'a.b'),
        ),
    )->when(fn (int $a, int $b) => $a !== $b)->then(function (int $a, int $b, string $name) {
        freshHttp();
        config()->set('datadis-client.accounts.a', ['username' => nifOf($a), 'password' => 'pa']);
        config()->set('datadis-client.accounts.b', ['username' => nifOf($b), 'password' => 'pb']);
        $expected = ['default' => '00000000T', 'a' => nifOf($a), 'b' => nifOf($b)][$name] ?? null;

        if ($expected === null) {
            expect(fn () => app(Manager::class)->account($name))->toThrow(InvalidArgumentException::class);
            expect(fn () => app(Manager::class)->publicApi($name))->toThrow(InvalidArgumentException::class);

            return;
        }

        app(Manager::class)->account($name)->getSupplies();

        expect(array_column(logins(), 'username'))->toBe([$expected]);
    });
});

it('builds a working guard for every application key that gives a usable secret, and refuses the rest', function () {
    $this->limitTo(iterations())->forAll(Generators::oneOf(
        Generators::string(),
        Generators::elements('', 'base64:', 'base64:MA==', 'base64:!!!', 'base64:'.base64_encode('short'), 'base64:'.base64_encode(str_repeat('k', 32)), str_repeat('k', 16)),
    ))->then(function (string $key) {
        freshHttp();
        config()->set('datadis-client.ledger.key', null);
        config()->set('app.key', $key);
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
        $usable = strlen($decoded === false || $decoded === '' ? $key : $decoded) >= 16;

        if (! $usable) {
            expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class);

            return;
        }

        $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

        expect(fn () => app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo()))->toThrow(RepetitionWindowException::class);
    });
});

it('forgets the guard when the secret changes, as documented', function () {
    $this->limitTo(iterations())->forAll(Generators::string(), Generators::string())
        ->when(fn (string $a, string $b) => $a !== $b)
        ->then(function (string $a, string $b) {
            freshHttp();
            config()->set('datadis-client.ledger.key', 'first-'.str_pad($a, 16, 'x'));
            $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
            app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

            config()->set('datadis-client.ledger.key', 'second-'.str_pad($b, 16, 'y'));
            app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

            expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(2);
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
