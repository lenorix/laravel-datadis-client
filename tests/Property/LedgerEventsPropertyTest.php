<?php

use Eris\Generators;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

/*
 * The guard against the wire, over random sequences of calls: what the events say must be what the HTTP client did, and
 * the guard must refuse exactly the queries an oracle of the 24 hour rule says. The oracle knows what the key of a query
 * is (consumption keeps the holder, maximum power ignores it), the window (24 hours and 10 minutes, free at exactly that
 * age) and that a login that fails frees the query it had claimed.
 */
it('says with its events what the guard did on the wire, whatever the sequence of calls', function () {
    $window = 24 * 3600 + 600;
    $broken = nifOf(7);
    $usernames = ['default' => '00000000T', 'tenant.east' => nifOf(5), 'broken' => $broken];
    $holders = [null, '12345678Z'];
    $steps = Generators::tuple(
        Generators::choose(0, 3),            // 0 consumption, 1 maximum power, 2 a call whose login fails, 3 time passes
        Generators::choose(0, 1),            // account: default or tenant.east
        Generators::choose(0, 2),            // month
        Generators::choose(0, 1),            // holder
        Generators::elements(0, 60, 3600, 86400, $window - 1, $window, $window + 1, 90000),
    );

    $this->limitTo(min(iterations(), 60))->forAll(Generators::seq($steps))->disableShrinking()->then(function (array $sequence) use ($window, $broken, $usernames, $holders) {
        config()->set('datadis-client.accounts', [
            'default' => ['username' => $usernames['default'], 'password' => 'x'],
            'tenant.east' => ['username' => $usernames['tenant.east'], 'password' => 'y'],
            'broken' => ['username' => $broken, 'password' => 'z'],
        ]);
        FakesDatadis::fake(['*/nikola-auth/tokens/login' => function (Request $request) use ($broken) {
            if ($request['username'] === $broken) {
                throw new ConnectionException('the login is down');
            }

            return Http::response(FakesDatadis::token(), 200, ['Content-Type' => 'text/plain']);
        }]);
        app('cache')->store()->clear();
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00', 'Europe/Madrid'));

        $events = [];
        Event::listen(DatadisLedgerChanged::class, function (DatadisLedgerChanged $event) use (&$events) {
            $events[] = $event;
        });
        $manager = app(LaravelDatadisClient::class);

        $lastSent = [];   // identity => when it last went out
        $keys = [];       // identity => the key the events gave it
        $claimed = $released = $refusedByGuard = $refusedEvents = 0;

        foreach ($sequence as [$kind, $accountChoice, $monthChoice, $holderChoice, $seconds]) {
            if ($kind === 3) {
                Carbon::setTestNow(now()->addSeconds($seconds));

                continue;
            }

            $account = $kind === 2 ? 'broken' : ['default', 'tenant.east'][$accountChoice];
            $month = Month::of(2026, 4 + $monthChoice);
            $holder = $holders[$holderChoice];
            $client = $manager->account($account);
            $client = $holder === null ? $client : $client->forHolder(Nif::fromString($holder));
            $maxPower = $kind === 1;
            // The key of the query: consumption keeps the holder, maximum power does not.
            $identity = $usernames[$account].'|'.$month->format().'|'.($maxPower ? 'max' : 'consumption:'.($holder ?? '-'));
            $before = count($events);
            $age = isset($lastSent[$identity]) ? now()->getTimestamp() - $lastSent[$identity] : null;
            $refusedExpected = $age !== null && $age < $window;

            try {
                $maxPower ? $client->getMaxPower(Cups::fromString(CUPS), '2', $month) : $client->getConsumptionData(Cups::fromString(CUPS), '2', 5, $month);
                $outcome = 'sent';
            } catch (RepetitionWindowException $e) {
                $outcome = 'refused';
                $refusedByGuard++;
            } catch (TransportException) {
                $outcome = 'unsent';
            }

            $mine = array_slice($events, $before);
            $kinds = array_map(fn ($event) => $event->kind, $mine);

            if ($refusedExpected) {
                expect($outcome)->toBe('refused', "a repeat at age {$age} must be refused: {$identity}");
                expect($kinds)->toBe([LedgerEventKind::Refused]);
                expect($mine[0]->availableAt->getTimestamp())->toBe($lastSent[$identity] + $window);
                expect($mine[0]->lastAttemptAt->getTimestamp())->toBe($lastSent[$identity]);
                $refusedEvents++;
            } elseif ($kind === 2) {
                expect($outcome)->toBe('unsent');
                expect($kinds)->toBe([LedgerEventKind::Claimed, LedgerEventKind::Released]);   // claimed, never left, freed
                $claimed++;
                $released++;
                unset($lastSent[$identity]);
            } else {
                expect($outcome)->toBe('sent', 'a query at age '.var_export($age, true)." must go: {$identity}");
                expect($kinds)->toBe([LedgerEventKind::Claimed]);
                $lastSent[$identity] = now()->getTimestamp();
                $claimed++;
            }

            foreach ($mine as $event) {
                expect($event->account)->toBe($account);
                $keys[$identity] ??= $event->key;
                expect($event->key)->toBe($keys[$identity], 'the same query has the same key');
            }
        }

        // The wire says the same as the events.
        $sent = Http::recorded(fn (Request $r) => preg_match('/get-(consumption-data|max-power)/', $r->url()) === 1)->count();
        expect($sent)->toBe($claimed - $released);
        expect($refusedEvents)->toBe($refusedByGuard);
        // Different queries have different keys.
        expect(count(array_unique($keys)))->toBe(count($keys));
        // Nothing personal in what a listener, a log or Telescope would record.
        $recorded = json_encode($events).serialize($events);
        expect($recorded)->not->toContain(CUPS)->not->toContain('12345678Z')->not->toContain('00000000T')->not->toContain($broken)->not->toContain($usernames['tenant.east']);
    });
})->after(fn () => Carbon::setTestNow());
