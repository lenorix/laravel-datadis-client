<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;

it('ignores http options with numeric keys, as Guzzle would', function () {
    app()['env'] = 'production';
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'text/plain'], fakeToken()), new Response(200, ['Content-Type' => 'application/json'], json_encode(['supplies' => [], 'distributorError' => []]))]);
    config()->set('datadis-client.http.stack', 'guzzle');
    config()->set('datadis-client.http.options', [0 => 'junk', 7 => true, 'handler' => HandlerStack::create($mock)]);
    $r = app(DatadisClient::class)->getSupplies();
    expect($r->records)->toBe([]);
});

it('takes the HTTP stack whatever its case or its blanks', function (string $stack) {
    config()->set('datadis-client.http.stack', $stack);
    fakeEverything();

    expect(app(DatadisClient::class)->getSupplies()->records)->toHaveCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'get-supplies'));
})->with([' laravel ', 'LARAVEL', "\tLaravel\n"]);
