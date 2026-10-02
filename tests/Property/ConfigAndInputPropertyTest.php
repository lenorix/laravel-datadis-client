<?php

use Eris\Generators;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

const ABSENT = '__absent__';

/** What the retry settings mean, written apart from the code under test: 'ok' or 'invalid'. */
function retryOutcome(mixed $max, mixed $base, mixed $longest): string
{
    $whole = static function (mixed $value, int $default): ?int {
        return match (true) {
            $value === ABSENT || $value === null => $default,
            is_int($value) => $value,
            is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1 => (int) trim($value),
            default => null,   // a float, a word, true...
        };
    };

    [$max, $base, $longest] = [$whole($max, 2), $whole($base, 1000), $whole($longest, 30000)];

    return match (true) {
        $max === null || $base === null || $longest === null => 'invalid',
        $max < 0 || $max > 10 => 'invalid',
        $max === 0 => 'ok',
        default => $base >= 1 && $longest >= $base ? 'ok' : 'invalid',
    };
}

function outcomeOf(Closure $build): string
{
    try {
        $build();

        return 'ok';
    } catch (ConfigurationException) {
        return 'invalid';
    }
}

it('accepts exactly the retry settings that can work', function () {
    $pool = [ABSENT, null, -1, 0, 1, 2, 5, 10, 11, '3', ' 7 ', '-2', 'many', 1.5, true, 100, 30000];

    $this->limitTo(iterations() * 2)->forAll(
        Generators::elements(...$pool),
        Generators::elements(...$pool),
        Generators::elements(...$pool),
    )->then(function (mixed $max, mixed $base, mixed $longest) {
        $retries = array_filter(['max' => $max, 'base_delay_ms' => $base, 'max_delay_ms' => $longest], fn ($value) => $value !== ABSENT);
        config()->set('datadis-client.http.retries', $retries);

        expect(outcomeOf(fn () => app(Manager::class)->account()))->toBe(retryOutcome($max, $base, $longest));
        expect(outcomeOf(fn () => app(Manager::class)->publicApi()))->toBe(retryOutcome($max, $base, $longest));
    });
});

it('accepts exactly the HTTP stack settings that cannot reach Datadis from a test', function () {
    $this->limitTo(iterations() * 2)->forAll(
        Generators::elements(null, '', 'laravel', 'guzzle', 'curl', 'LARAVEL', 5, false),
        Generators::elements('testing', 'production', 'local'),
        Generators::bool(),
    )->then(function (mixed $stack, string $environment, bool $hasHandler) {
        config()->set('datadis-client.http.stack', $stack);
        config()->set('datadis-client.http.options', $hasHandler ? ['handler' => HandlerStack::create(new MockHandler)] : []);
        app()['env'] = $environment;

        $effective = $stack === null || $stack === '' ? ($environment === 'testing' ? 'laravel' : 'guzzle') : $stack;
        $expected = match (true) {
            ! in_array($effective, ['laravel', 'guzzle'], true) => 'invalid',
            $effective === 'guzzle' && $environment === 'testing' && ! $hasHandler => 'invalid',
            default => 'ok',
        };

        expect(outcomeOf(fn () => app(Manager::class)->account()))->toBe($expected);
    });
});

/** A command with exactly one input that is wrong; the others are fine. */
function commandWithBadInput(string $kind, string $bad): array
{
    $month = monthsAgo()->format();

    return match ($kind) {
        'month' => ['datadis:consumption', ['cups' => CUPS, 'month' => $bad]],
        'last month' => ['datadis:consumption', ['cups' => CUPS, 'month' => $month, '--to' => $bad]],
        'cups to read' => ['datadis:consumption', ['cups' => $bad, 'month' => $month]],
        'cups of a contract' => ['datadis:contract', ['cups' => $bad]],
        'nif to authorize' => ['datadis:authorize', ['nif' => $bad]],
        'start date' => ['datadis:authorize', ['nif' => '12345678Z', '--from' => $bad]],
        'end date' => ['datadis:authorize', ['nif' => '12345678Z', '--to' => $bad]],
        'cups to authorize' => ['datadis:authorize', ['nif' => '12345678Z', '--cups' => [$bad]]],
        'nif to cancel' => ['datadis:authorization:cancel', ['nif' => $bad]],
        'holder' => ['datadis:supplies', ['--holder' => $bad]],
    };
}

function wellFormed(string $kind, string $value): bool
{
    // An empty option counts as not given, as documented: the first month only, every supply, no holder.
    if ($value === '' && in_array($kind, ['last month', 'start date', 'end date', 'holder'], true)) {
        return true;
    }

    return match ($kind) {
        'month', 'last month' => preg_match('/^\d{4}[-\/](0[1-9]|1[0-2])$/D', $value) === 1,
        'cups to read', 'cups of a contract', 'cups to authorize' => Cups::isValid($value),
        'nif to authorize', 'nif to cancel', 'holder' => Nif::isValid($value),
        'start date', 'end date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
    };
}

it('never sends a request for a malformed command input, whatever it is', function () {
    $kinds = ['month', 'last month', 'cups to read', 'cups of a contract', 'nif to authorize', 'start date', 'end date', 'cups to authorize', 'nif to cancel', 'holder'];

    $this->limitTo(iterations() * 3)->forAll(Generators::elements(...$kinds), Generators::oneOf(
        Generators::string(),
        Generators::elements('', ' ', '-', '--x', '2026-13', '2026/00', '2026-02-30', 'ES', "ES0000000000000000AA0A\n", '00000000T ', '٣٣٣٣-٠١'),
    ))->when(fn (string $kind, string $value) => ! wellFormed($kind, $value))->then(function (string $kind, string $value) {
        freshHttp();
        [$command, $input] = commandWithBadInput($kind, $value);

        $code = Artisan::call($command, $input);

        expect($code)->toBe(1, "{$kind}: ".json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
        expect(Http::recorded())->toHaveCount(0, "{$kind}: ".json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
    });
});
