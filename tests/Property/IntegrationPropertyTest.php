<?php

use Eris\Generators;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

it('takes the credentials from services when set and keeps every other account option', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(1, 99999999),   // account username
        Generators::choose(1, 99999999),   // services username
        Generators::elements('', 'pw-account'),   // account password
        Generators::elements(null, '', 'pw-services'),   // services password
        Generators::elements('v1', 'v2'),
        Generators::bool(),                 // does services set a username
    )->then(function (int $accountNumber, int $serviceNumber, string $accountPassword, ?string $servicePassword, string $version, bool $servicesHasUser) {
        $accountPassword = $accountPassword === '' ? 'fallback' : $accountPassword;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config()->set('datadis-client.accounts.default', ['username' => nifOf($accountNumber), 'password' => $accountPassword, 'api_version' => $version]);
        config()->set('services.datadis', ['username' => $servicesHasUser ? nifOf($serviceNumber) : null, 'password' => $servicePassword]);
        Http::fake([
            '*/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
            '*get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []]),
        ]);

        app(DatadisClient::class)->getSupplies();

        [$login] = logins();
        expect($login['username'])->toBe($servicesHasUser ? nifOf($serviceNumber) : nifOf($accountNumber));
        expect($login['password'])->toBe($servicePassword !== null && $servicePassword !== '' ? $servicePassword : $accountPassword);
        // The account's api_version survives: v2 paths end in -v2, v1 paths do not.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $version === 'v2' ? '-v2' : 'get-supplies'));
    });
});

it('guards each holder apart: asking for another holder is allowed, repeating one is refused', function () {
    $this->limitTo(iterations())->forAll(
        Generators::choose(1, 99999999),
        Generators::choose(1, 99999999),
        Generators::choose(1, 12),
    )->when(fn (int $a, int $b) => $a !== $b && nifOf($a) !== '00000000T' && nifOf($b) !== '00000000T')
        ->then(function (int $a, int $b, int $month) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            app('cache')->store()->clear();
            fakeDatadis();
            $client = app(DatadisClient::class);
            $supply = $client->findSupply(Cups::fromString(CUPS));
            $month = Month::of(2025, $month);

            $client->forHolder(Nif::fromString(nifOf($a)))->getConsumptionDataOf($supply, $month);
            $client->forHolder(Nif::fromString(nifOf($b)))->getConsumptionDataOf($supply, $month);

            expect(fn () => app(DatadisClient::class)->forHolder(Nif::fromString(nifOf($a)))->getConsumptionDataOf($supply, $month))
                ->toThrow(RepetitionWindowException::class);
            expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(2);
        });
});

it('sends a guarded query at most once however many clients claim it', function () {
    $this->limitTo(iterations())->forAll(Generators::choose(2, 8), Generators::choose(1, 12))->then(function (int $claims, int $month) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        app('cache')->store()->clear();
        fakeDatadis();
        $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
        $refused = 0;

        for ($i = 0; $i < $claims; $i++) {
            try {
                app(Manager::class)->account()->getConsumptionDataOf($supply, Month::of(2025, $month));
            } catch (RepetitionWindowException) {
                $refused++;
            }
        }

        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(1);
        expect($refused)->toBe($claims - 1);
    });
});
