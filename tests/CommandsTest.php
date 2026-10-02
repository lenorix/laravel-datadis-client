<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

const DISTRIBUTOR_DOWN = ['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500', 'errorDescription' => 'distributor is down'];

/** Datadis with one supply and the given answers for the other endpoints. */
function fakeForCommands(array $answers = []): void
{
    $json = fn (array $body) => Http::response($body);

    Http::fake($answers + [
        '*/nikola-auth/tokens/login' => Http::response(fakeToken(), 200, ['Content-Type' => 'text/plain']),
        '*/get-supplies*' => $json(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
        '*/get-contract-detail*' => $json(['contract' => [[
            'cups' => CUPS, 'distributor' => 'EDISTRIBUCION', 'marketer' => 'A MARKETER', 'accessFare' => 'BAJA TENSION y POTENCIA <= 15 kW',
            'contractedPowerkW' => [3.45, 3.45], 'startDate' => '2020/01/01', 'endDate' => '',
        ]], 'distributorError' => []]),
        '*/get-consumption-data*' => $json(['timeCurve' => [[
            'cups' => CUPS, 'date' => '2026/07/01', 'time' => '01:00', 'consumptionKWh' => 0.123, 'obtainMethod' => 'Real',
        ]], 'distributorError' => []]),
        '*/list-authorization*' => $json(['authorizations' => [[
            'id' => '1', 'ownerDocument' => '00000000T', 'requesterDocument' => '12345678Z', 'cups' => CUPS, 'status' => 'ACTIVE',
            'validityDateStart' => '2026/01/01', 'validityDateEnd' => '2026/12/31',
        ]], 'distributorError' => []]),
        '*/new-authorization*' => Http::response('Authorization created', 200, ['Content-Type' => 'text/plain']),
        '*/cancel-authorization*' => Http::response('Authorization cancelled', 200, ['Content-Type' => 'text/plain']),
    ]);
}

/** @return array{int, string} the exit code and everything the command printed */
function runCommand(string $command): array
{
    $code = Artisan::call($command);

    return [$code, Artisan::output()];
}

it('shows the contract of a supply with its access tariff', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:contract '.CUPS);

    expect($code)->toBe(0);
    expect($output)->toContain('T20TD', '3.45 / 3.45', 'open');
});

it('warns about a distributor that failed while reading the contract', function () {
    fakeForCommands(['*/get-contract-detail*' => Http::response(['contract' => [], 'distributorError' => [DISTRIBUTOR_DOWN]])]);

    [$code, $output] = runCommand('datadis:contract '.CUPS);

    expect($code)->toBe(0);
    expect($output)->toContain('distributor is down');
});

it('refuses a supply the account cannot see, before asking for data', function (string $command) {
    fakeForCommands(['*/get-supplies*' => Http::response(['supplies' => [], 'distributorError' => []])]);

    [$code, $output] = runCommand($command);

    expect($code)->toBe(1);
    expect($output)->toContain('cannot see that supply');
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-contract-detail') || str_contains($r->url(), 'get-consumption-data')))->toHaveCount(0);
})->with(['contract' => 'datadis:contract '.CUPS, 'consumption' => 'datadis:consumption '.CUPS.' 2026-07']);

it('reads a month of consumption, warning that the query counts', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo()->format());

    expect($code)->toBe(0);
    expect($output)->toContain('24 hours', '0.123');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-consumption-data') && str_contains($r->url(), 'measurementType=0'));
});

it('accepts months as YYYY-MM, a last month and quarter-hourly readings', function () {
    fakeForCommands();
    $from = monthsAgo(3);
    $to = monthsAgo(1);

    [$code] = runCommand(sprintf('datadis:consumption %s %04d-%02d --to=%04d-%02d --quarter-hourly', CUPS, $from->year, $from->month, $to->year, $to->month));

    expect($code)->toBe(0);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')
        && str_contains($r->url(), 'startDate='.urlencode($from->format()))
        && str_contains($r->url(), 'endDate='.urlencode($to->format()))
        && str_contains($r->url(), 'measurementType=1'));
});

it('says why a consumption answer is empty', function (array $answer, string $message) {
    fakeForCommands(['*/get-consumption-data*' => Http::response($answer)]);

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo()->format());

    expect($code)->toBe(0);
    expect($output)->toContain($message);
})->with([
    'not published' => [['timeCurve' => [], 'distributorError' => []], 'Nothing published'],
    'a distributor failed' => [['timeCurve' => [], 'distributorError' => [DISTRIBUTOR_DOWN]], 'A distributor failed'],
]);

it('lists the distributor errors beside the readings', function () {
    fakeForCommands(['*/get-consumption-data*' => Http::response(['timeCurve' => [[
        'cups' => CUPS, 'date' => '2026/07/01', 'time' => '01:00', 'consumptionKWh' => 1, 'obtainMethod' => 'Real',
    ]], 'distributorError' => [DISTRIBUTOR_DOWN]])]);

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo()->format());

    expect($code)->toBe(0);
    expect($output)->toContain('distributor is down');
});

it('refuses a bad month before asking for data', function (string $month) {
    fakeForCommands();

    [$code] = runCommand('datadis:consumption '.CUPS.' '.$month);

    expect($code)->toBe(1);
    expect(Http::recorded())->toHaveCount(0);   // not even the login
})->with(['not a month' => 'july', 'month 13' => '2026-13', 'a day' => '2026-07-01']);

it('refuses a bad last month or a bad CUPS before sending anything', function (string $command) {
    fakeForCommands();

    [$code] = runCommand($command);

    expect($code)->toBe(1);
    expect(Http::recorded())->toHaveCount(0);
})->with([
    'a bad last month' => 'datadis:consumption '.CUPS.' 2026-01 --to=soon',
    'a bad CUPS to read' => 'datadis:consumption ES123 2026-01',
    'a bad CUPS for the contract' => 'datadis:contract ES123',
    'a bad holder' => 'datadis:contract '.CUPS.' --holder=nope',
]);

it('fails the second time the same consumption is read, without sending it', function () {
    fakeForCommands();
    $month = monthsAgo()->format();

    [$first] = runCommand('datadis:consumption '.CUPS.' '.$month);
    [$second] = runCommand('datadis:consumption '.CUPS.' '.$month);

    expect([$first, $second])->toBe([0, 1]);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(1);
});

it('lists the authorizations', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:authorizations');

    expect($code)->toBe(0);
    expect($output)->toContain('ACTIVE', '2026-12-31', '12345678Z');
});

it('lets a third party read some supplies for a period', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:authorize 12345678Z --cups='.CUPS.' --from=2026-01-01 --to=2026-12-31');

    expect($code)->toBe(0);
    expect($output)->toContain('Authorization created');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'new-authorization')
        && str_contains($r->url(), 'authorizedNif=12345678Z')
        && str_contains($r->url(), 'startDate='.urlencode('2026/01/01'))
        && str_contains($r->url(), 'endDate='.urlencode('2026/12/31'))
        && str_contains($r->url(), 'cups='.CUPS));
});

it('lets a third party read every supply when no CUPS is given', function () {
    fakeForCommands();

    [$code] = runCommand('datadis:authorize 12345678Z');

    expect($code)->toBe(0);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'new-authorization') && ! str_contains($r->url(), 'cups='));
});

it('refuses what it cannot send as an authorization', function (string $command) {
    fakeForCommands();

    [$code] = runCommand($command);

    expect($code)->toBe(1);
    expect(Http::recorded())->toHaveCount(0);
})->with([
    'a bad NIF' => 'datadis:authorize nope',
    'a bad date' => 'datadis:authorize 12345678Z --from=01/01/2026',
    'an impossible date' => 'datadis:authorize 12345678Z --to=2026-02-30',
    'a bad CUPS' => 'datadis:authorize 12345678Z --cups=ES123',
    'ending before it starts' => 'datadis:authorize 12345678Z --from=2026-12-31 --to=2026-01-01',
]);

it('takes a third party\'s access away', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:authorization:cancel 12345678Z --cups='.CUPS);

    expect($code)->toBe(0);
    expect($output)->toContain('Authorization cancelled');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cancel-authorization') && str_contains($r->url(), 'authorizedNif=12345678Z'));
});

it('lists the commands of the package', function () {
    expect(array_keys(Artisan::all()))->toContain('datadis:supplies', 'datadis:contract', 'datadis:consumption', 'datadis:authorizations', 'datadis:authorize', 'datadis:authorization:cancel');
});
