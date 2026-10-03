<?php

namespace Lenorix\LaravelDatadisClient\Testing;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Datadis answering every endpoint of the client, for the tests of an application: `FakesDatadis::fake()` instead of
 * `Http::fake()`.
 *
 * It starts from a fresh HTTP factory, because `Http::fake()` accumulates stubs and the first one that matches wins: a
 * second fake after a first one would be silently ignored. Your own answers go **before** the defaults, so they win; the
 * login answers with a token whose `exp` is a day after `now()`, so a test that travels in time gets a token of its day;
 * and a URL nobody answers fails (`Http::preventStrayRequests()`) instead of reaching Datadis.
 *
 * It does not touch the cache: a token or a guard entry from an earlier test stays where it was (use the `array` store,
 * which Laravel empties between tests).
 */
final class FakesDatadis
{
    /** A CUPS the default supply has, with a valid shape. */
    public const string CUPS = 'ES0000000000000000AA0A';

    /**
     * @param  array<string, mixed>  $answers  URL patterns and the responses they get (`'*\/get-max-power*' => Http::response(...)`), tried before the defaults
     * @param  array<string, mixed>  $supply  fields of the default supply row that you want to change (`validDateFrom`, `pointType`...)
     */
    public static function fake(array $answers = [], array $supply = []): Factory
    {
        $factory = new Factory;
        Http::swap($factory);
        Http::preventStrayRequests();

        $list = fn (string $key) => Http::response([$key => [], 'distributorError' => []]);
        $text = fn (string $body) => Http::response($body, 200, ['Content-Type' => 'text/plain']);

        // Yours first: the first stub that matches wins, and a `+` keeps the left side's order.
        Http::fake($answers + [
            '*/nikola-auth/tokens/login' => fn () => $text(self::token()),
            '*/get-supplies*' => Http::response(['supplies' => [array_replace([
                'cups' => self::CUPS, 'distributor' => 'A DISTRIBUTOR', 'pointType' => 5, 'distributorCode' => '2',
                'validDateFrom' => '2020/01/01', 'validDateTo' => '',
            ], $supply)], 'distributorError' => []]),
            '*/get-distributors-with-supplies*' => Http::response(['distributorError' => []]),
            '*/get-contract-detail*' => $list('contract'),
            '*/get-consumption-data*' => $list('timeCurve'),
            '*/get-max-power*' => $list('maxPower'),
            '*/get-reactive-data*' => Http::response(['reactiveEnergy' => [], 'distributorError' => []]),
            '*/new-authorization*' => $text('created'),
            '*/cancel-authorization*' => $text('cancelled'),
            '*/list-authorization*' => $list('authorizations'),
            '*/get-groups*' => $list('groups'),
            '*/partner-user-list*' => $list('users'),
            '*/partner-delete-user*' => $text('unlinked'),
            '*/partner-agreement-date*' => Http::response(['partnerAgreementDate' => null]),
            '*/api-public/api-*' => Http::response([]),
        ]);

        return $factory;
    }

    /** A fake token, as the real login answers it: unsigned, with `iat` now and `exp` a day later (the signature is never checked). */
    public static function token(): string
    {
        $encode = fn (string $json) => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encode('{"alg":"HS512"}').'.'.$encode(json_encode(['sub' => 'account', 'iat' => now()->timestamp, 'exp' => now()->addDay()->timestamp], JSON_THROW_ON_ERROR)).'.signature';
    }
}
