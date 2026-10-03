<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

it('lists supplies with the artisan command', function () {
    FakesDatadis::fake();

    $this->artisan('datadis:supplies')->expectsOutputToContain(CUPS)->assertSuccessful();
});

it('fails the command cleanly for an unknown account', function () {
    $this->artisan('datadis:supplies --account=nope')->assertFailed();
});

it('sends the holder as authorizedNif from the command', function () {
    FakesDatadis::fake();

    $this->artisan('datadis:supplies --holder=12345678Z')->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && str_contains($r->url(), 'authorizedNif=12345678Z'));
});

it('lists the supplies of a named account, logging in as that account', function () {
    config()->set('datadis-client.accounts.other', ['username' => '12345678Z', 'password' => 'other-secret']);
    FakesDatadis::fake();

    $this->artisan('datadis:supplies --account=other')->expectsOutputToContain(CUPS)->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '12345678Z');
});

it('fails the command cleanly for a holder that is not a valid NIF', function (string $holder) {
    FakesDatadis::fake();

    $this->artisan("datadis:supplies --holder={$holder}")->assertFailed();

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-supplies')))->toHaveCount(0);
})->with(['not a nif' => 'nope', 'wrong control letter' => '12345678A', 'too short' => '1234']);
