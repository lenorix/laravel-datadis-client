<?php

use Eris\Generators;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

/*
 * Where an account's settings come from, against an oracle: `services.datadis` wins over `datadis-client.accounts.default`
 * for every key it sets to something (blank text and null do not count), a key is its underscore or its dash spelling
 * (the underscore one first, as the client reads them), and what is left comes from the account, then the default.
 */
it('settles the username, the password and the API version as the oracle says, whatever the spellings and blanks', function () {
    $value = fn (array $options) => Generators::elements(...$options);
    $this->limitTo(iterations())->forAll(
        $value([null, '', '   ', 'sp-1']),             // services password
        $value([null, '', '   ', nifOf(11)]),          // services username
        $value([null, '', '   ', 'v1', 'v2']),         // services api_version
        $value([null, '', 'v1', 'v2']),                // services api-version
        $value([null, 'v1', 'v2']),                    // account api_version
        $value([null, 'v1', 'v2']),                    // account api-version
        $value(['ap-1', 'ap-2']),                      // account password
        $value([nifOf(21), nifOf(22)]),                // account username
    )->then(function ($servicePassword, $serviceUsername, $serviceUnderscore, $serviceDash, $accountUnderscore, $accountDash, $accountPassword, $accountUsername) {
        $blank = fn ($v) => $v === null || trim((string) $v) === '';
        $first = fn (...$candidates) => collect($candidates)->first(fn ($v) => ! $blank($v));

        $services = array_filter(['username' => $serviceUsername, 'password' => $servicePassword, 'api_version' => $serviceUnderscore, 'api-version' => $serviceDash], fn ($v) => $v !== null);
        $account = array_filter(['username' => $accountUsername, 'password' => $accountPassword, 'api_version' => $accountUnderscore, 'api-version' => $accountDash], fn ($v) => $v !== null);
        config()->set('services.datadis', $services);
        config()->set('datadis-client.accounts.default', $account);

        FakesDatadis::fake();
        app('cache')->store()->clear();   // a token from the last case would skip the login
        app(DatadisClient::class)->getSupplies();

        [$login] = logins();
        $version = $first($serviceUnderscore, $serviceDash, $accountUnderscore, $accountDash) ?? 'v2';

        expect($login['username'])->toBe($first($serviceUsername, $accountUsername));
        expect($login['password'])->toBe($first($servicePassword, $accountPassword));
        $suppliesUrl = Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies'))->first()[0]->url();
        expect(str_contains($suppliesUrl, 'get-supplies-v2'))->toBe($version === 'v2', "version {$version}: {$suppliesUrl}");
    });
});
