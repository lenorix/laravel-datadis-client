<?php

use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\LaravelDatadisClient\Internal\GuardLedgers;
use Lenorix\LaravelDatadisClient\Internal\Retries;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** What the settings of the package do when they are odd: each one is a decision a deployment makes with a typo. */
function privately(string $method, mixed ...$arguments): mixed
{
    return (fn () => $this->{$method}(...$arguments))->call(app(Manager::class));
}

it('falls back to the account named default when the default account is blank or not a text', function (mixed $setting) {
    config()->set('datadis-client.default', $setting);
    fakeEverything();

    app(DatadisClient::class)->getSupplies();

    expect(array_column(logins(), 'username'))->toBe(['00000000T']);
})->with(['an empty text' => [''], 'blanks' => ['   '], 'nothing' => [null], 'a number' => [5], 'a list' => [['x']]]);

it('lets services.datadis win over the account whichever way a key is spelled', function (string $services, string $account) {
    config()->set('services.datadis', ['username' => '00000000T', 'password' => 'secret', $services => 'v1']);
    config()->set('datadis-client.accounts.default', [$account => 'v2']);
    fakeEverything();

    app(DatadisClient::class)->getSupplies();

    // v1 has no `-v2` suffix on its paths: services decided.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'get-supplies') && ! str_contains($request->url(), 'get-supplies-v2'));
})->with([
    'api_version over api-version' => ['api_version', 'api-version'],
    'api-version over api_version' => ['api-version', 'api_version'],
]);

it('says which levels the report level may be, and which stacks the HTTP stack may be', function () {
    config()->set('datadis-client.report_level', 'loud');
    expect(fn () => app(Manager::class)->reportLevel())->toThrow(ConfigurationException::class, 'datadis-client.report_level must be null or one of emergency, alert, critical, error, warning, notice, info, debug.');

    config()->set('datadis-client.report_level', null);   // account() checks it first
    config()->set('datadis-client.http.stack', 'bogus');
    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, '"bogus"');

    config()->set('datadis-client.http.stack', 5);
    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'int given');
});

it('retries twice with delays of one and thirty seconds when nothing is said, and takes whole numbers as text', function () {
    $of = function (array $retries) {
        config()->set('datadis-client.http.retries', $retries);
        $client = (new Retries(app()))->wrap(new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new LogicException('not sent');
            }
        });
        $read = fn (string $name) => (new ReflectionProperty(RetryingClient::class, $name))->getValue($client);

        return [$read('maxRetries'), $read('baseDelayMs'), $read('maxDelayMs')];
    };

    expect($of(['max' => null, 'base_delay_ms' => '', 'max_delay_ms' => null]))->toBe([2, 1000, 30000]);
    expect($of(['max' => '3', 'base_delay_ms' => ' 5 ', 'max_delay_ms' => '50']))->toBe([3, 5, 50]);
});

it('derives the secret of the guard from the application key the way it says', function (string $appKey, string $bytes) {
    config()->set('datadis-client.ledger.key', '');
    config()->set('app.key', $appKey);

    expect((new GuardLedgers(app()))->secret())->toBe(hash_hmac('sha256', 'laravel-datadis-client: 24 hour guard', $bytes, true));
})->with([
    'a base64 key is decoded' => ['base64:'.base64_encode('0123456789abcdef0123456789abcdef'), '0123456789abcdef0123456789abcdef'],
    'a text is used as it is' => ['0123456789abcdef', '0123456789abcdef'],
    'a base64 that does not decode is used as it is' => ['base64:!!!!!!!!!!!!!!!!!!', 'base64:!!!!!!!!!!!!!!!!!!'],
    'a base64 with a bad character is not half decoded' => ['base64:AAAAAAAAAAAAAAAA!!', 'base64:AAAAAAAAAAAAAAAA!!'],
    'sixteen bytes are enough' => ['0123456789abcdef', '0123456789abcdef'],
]);

it('uses a secret of its own as it is, and refuses what is not one', function () {
    config()->set('datadis-client.ledger.key', 'my own secret of enough bytes');
    expect((new GuardLedgers(app()))->secret())->toBe('my own secret of enough bytes');

    config()->set('datadis-client.ledger.key', 5);
    expect(fn () => (new GuardLedgers(app()))->secret())->toThrow(ConfigurationException::class, 'must be a text or null, int given');

    config()->set('datadis-client.ledger.key', null);
    config()->set('app.key', '');
    expect(fn () => (new GuardLedgers(app()))->secret())->toThrow(ConfigurationException::class, 'Set datadis-client.ledger.key');
});

it('refuses an application key shorter than sixteen bytes, and accepts exactly sixteen', function () {
    config()->set('datadis-client.ledger.key', null);

    config()->set('app.key', '0123456789abcde');   // fifteen
    expect(fn () => (new GuardLedgers(app()))->secret())->toThrow(ConfigurationException::class, 'too short');

    config()->set('app.key', '0123456789abcdef');   // sixteen
    expect((new GuardLedgers(app()))->secret())->toBeString();
});

it('accepts between none and ten retries, and no more', function (int $max, bool $accepted) {
    config()->set('datadis-client.http.retries', ['max' => $max, 'base_delay_ms' => 1, 'max_delay_ms' => 1]);
    $inner = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new LogicException('not sent');
        }
    };

    if (! $accepted) {
        expect(fn () => (new Retries(app()))->wrap($inner))->toThrow(ConfigurationException::class, 'between 0 and 10');

        return;
    }

    $client = (new Retries(app()))->wrap($inner);
    // None is the client itself, unwrapped; any other is wrapped in the retrying one.
    expect($max === 0 ? $client === $inner : $client instanceof RetryingClient)->toBeTrue();
})->with([[-1, false], [0, true], [1, true], [10, true], [11, false]]);

it('refuses delays that are not positive or that shrink', function (int $base, int $longest) {
    config()->set('datadis-client.http.retries', ['max' => 1, 'base_delay_ms' => $base, 'max_delay_ms' => $longest]);

    expect(fn () => (new Retries(app()))->wrap(Mockery::mock(ClientInterface::class)))->toThrow(ConfigurationException::class, 'delays must be positive');
})->with([[0, 5], [-1, 5], [10, 5]]);

it('ignores the blank values of services.datadis, so an empty .env line does not blank an account out', function (mixed $blank) {
    config()->set('services.datadis', ['username' => $blank, 'password' => $blank, 'timeout' => $blank]);
    config()->set('datadis-client.accounts.default', ['username' => '00000000T', 'password' => 'from the account', 'timeout' => 7]);
    fakeEverything();

    app(DatadisClient::class)->getSupplies();

    expect(array_column(logins(), 'username'))->toBe(['00000000T']);
})->with(['an empty text' => [''], 'blanks' => ['   '], 'nothing' => [null]]);

it('settles the settings of an account given with numeric keys, as a typo in a config file may leave them', function () {
    config()->set('services.datadis', ['username' => '00000000T', 'password' => 'secret', 0 => 'stray', 7 => 'strays']);
    config()->set('datadis-client.accounts.default', [3 => 'also stray', 'timeout' => 7]);
    fakeEverything();

    app(DatadisClient::class)->getSupplies();

    expect(array_column(logins(), 'username'))->toBe(['00000000T']);
});
