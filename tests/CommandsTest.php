<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Commands\DatadisCommand;
use Lenorix\LaravelDatadisClient\Tests\Support\FormatsOnceOutputStyle;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
            'cups' => CUPS, 'distributor' => 'A DISTRIBUTOR', 'marketer' => 'A MARKETER', 'accessFare' => 'BAJA TENSION y POTENCIA <= 15 kW',
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

it('says that nothing is published yet, which is not a failure', function () {
    fakeForCommands(['*/get-consumption-data*' => Http::response(['timeCurve' => [], 'distributorError' => []])]);

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo()->format());

    expect($code)->toBe(0);
    expect($output)->toContain('Nothing published');
});

/** The read commands, with the endpoint they read and the key and a row of its answer. */
function readCommands(): array
{
    return [
        'supplies' => ['datadis:supplies', '*/get-supplies*', 'supplies', ['cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '']],
        'contract' => ['datadis:contract '.CUPS, '*/get-contract-detail*', 'contract', ['cups' => CUPS, 'accessFare' => 'BAJA TENSION y POTENCIA <= 15 kW', 'contractedPowerkW' => [3.45, 3.45], 'startDate' => '2020/01/01', 'endDate' => '']],
        'consumption' => ['datadis:consumption '.CUPS.' '.monthsAgo()->format(), '*/get-consumption-data*', 'timeCurve', ['cups' => CUPS, 'date' => '2026/07/01', 'time' => '01:00', 'consumptionKWh' => 1, 'obtainMethod' => 'Real']],
        'authorizations' => ['datadis:authorizations', '*/list-authorization*', 'authorizations', ['id' => '1', 'ownerDocument' => '00000000T', 'requesterDocument' => '12345678Z', 'cups' => CUPS, 'status' => 'ACTIVE']],
    ];
}

it('fails when a distributor failed and no data came back, so a script can tell', function (string $name) {
    [$command, $endpoint, $key] = readCommands()[$name];
    fakeForCommands([$endpoint => Http::response([$key => [], 'distributorError' => [DISTRIBUTOR_DOWN]])]);

    [$code, $output] = runCommand($command);

    expect($code)->toBe(1);
    expect($output)->toContain('distributor is down', 'A distributor failed and no data came back');
})->with(array_keys(readCommands()));

it('only warns when a distributor failed beside real data', function (string $name) {
    [$command, $endpoint, $key, $row] = readCommands()[$name];
    fakeForCommands([$endpoint => Http::response([$key => [$row], 'distributorError' => [DISTRIBUTOR_DOWN]])]);

    [$code, $output] = runCommand($command);

    expect($code)->toBe(0);
    expect($output)->toContain('distributor is down');
    expect($output)->not->toContain('no data came back');
})->with(array_keys(readCommands()));

it('succeeds without a word when there is no data and no distributor failed', function (string $name) {
    [$command, $endpoint, $key] = readCommands()[$name];
    fakeForCommands([$endpoint => Http::response([$key => [], 'distributorError' => []])]);

    [$code, $output] = runCommand($command);

    // An empty supplies list or contract is an answer; the command has nothing to complain about.
    expect($code)->toBe(0);
    expect($output)->not->toContain('distributor');
})->with(array_keys(readCommands()));

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

it('refuses a reversed, a future or a too old range before sending anything', function (string $range) {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.$range);

    expect($code)->toBe(1);
    expect($output)->not->toBe('');
    expect(Http::recorded())->toHaveCount(0);
})->with([
    'reversed' => monthsAgo(1)->format().' --to='.monthsAgo(3)->format(),
    'in the future' => '2999-01',
    'too old' => '2001-01',
    'ends in the future' => monthsAgo(2)->format().' --to=2999-01',
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

it('does not take --holder in the commands that act for the account itself', function (string $command) {
    fakeForCommands();

    // Taking it would suggest the operation is made for that holder, when it is made for the account.
    expect(fn () => runCommand($command.' --holder=12345678Z'))->toThrow(RuntimeException::class, 'The "--holder" option does not exist.');
    expect(Http::recorded())->toHaveCount(0);
})->with([
    'datadis:authorizations',
    'datadis:authorize 12345678Z',
    'datadis:authorization:cancel 12345678Z',
]);

it('lists the authorizations of one owner', function () {
    fakeForCommands();

    [$code, $output] = runCommand('datadis:authorizations --owner=00000000T');

    expect($code)->toBe(0);
    expect($output)->toContain('ACTIVE');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'list-authorization') && str_contains($r->url(), 'ownerNif=00000000T'));
});

it('lists every authorization when no owner is given', function () {
    fakeForCommands();

    runCommand('datadis:authorizations');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'list-authorization') && ! str_contains($r->url(), 'ownerNif'));
});

it('refuses an owner that is not a NIF before sending anything', function () {
    fakeForCommands();

    [$code] = runCommand('datadis:authorizations --owner=nope');

    expect($code)->toBe(1);
    expect(Http::recorded())->toHaveCount(0);
});

it('still takes --holder where it reads the supplies of a holder', function (string $command, string $endpoint) {
    fakeForCommands();

    runCommand($command.' --holder=12345678Z');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), $endpoint) && str_contains($r->url(), 'authorizedNif=12345678Z'));
})->with([
    ['datadis:supplies', 'get-supplies'],
    ['datadis:contract '.CUPS, 'get-contract-detail'],
]);

it('lists the commands of the package', function () {
    expect(array_keys(Artisan::all()))->toContain('datadis:supplies', 'datadis:contract', 'datadis:consumption', 'datadis:authorizations', 'datadis:authorize', 'datadis:authorization:cancel');
});

it('prints every column of the supplies, the contract, the readings and the authorizations', function (string $command, array $headers, array $cells) {
    fakeForCommands();

    [$code, $output] = runCommand($command);

    expect($code)->toBe(0);
    expect($output)->toContain(...$headers);
    expect($output)->toContain(...$cells);
})->with([
    'supplies' => ['datadis:supplies', ['CUPS', 'Distributor', 'Point type', 'Valid from', 'Valid to', 'Queryable'], [CUPS, '| X', '| 5', '2020-01-01', 'yes']],
    'contract' => ['datadis:contract '.CUPS, ['Distributor', 'Marketer', 'Access tariff', 'Contracted power (kW)', 'From', 'To'], ['A DISTRIBUTOR', 'A MARKETER', 'T20TD', '3.45 / 3.45', '2020-01-01', 'open']],
    'consumption' => ['datadis:consumption '.CUPS.' '.monthsAgo()->format(), ['Date', 'Time', 'kWh', 'Method'], ['2026/07/01', '01:00', '0.123', 'Real']],
    'authorizations' => ['datadis:authorizations', ['Owner', 'Requester', 'CUPS', 'Status', 'From', 'To'], ['00000000T', '12345678Z', CUPS, 'ACTIVE', '2026-01-01', '2026-12-31']],
]);

it('shows the tariff text when the access tariff is not one of the known ones', function () {
    fakeForCommands(['*/get-contract-detail*' => Http::response(['contract' => [[
        'cups' => CUPS, 'accessFare' => 'SOMETHING UNKNOWN', 'contractedPowerkW' => [3.45, 3.45, 5.0], 'startDate' => '2020/01/01', 'endDate' => '2030/01/01',
    ]], 'distributorError' => []])]);

    [$code, $output] = runCommand('datadis:contract '.CUPS);

    expect($code)->toBe(0);
    expect($output)->toContain('SOMETHING UNKNOWN', '3.45 / 3.45 / 5', '2030-01-01');
});

it('takes an empty option as not given', function (string $command, Closure $check) {
    fakeForCommands();

    [$code] = runCommand($command);

    expect($code)->toBe(0);
    $check();
})->with([
    'an empty holder' => ['datadis:supplies --holder=', fn () => Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-supplies') && ! str_contains($r->url(), 'authorizedNif'))],
    'an empty account' => ['datadis:supplies --account=', fn () => Http::assertSent(fn (Request $r) => str_contains($r->url(), 'login') && $r['username'] === '00000000T')],
    'an empty last month' => ['datadis:consumption '.CUPS.' '.monthsAgo(2)->format().' --to=', fn () => Http::assertSent(fn (Request $r) => str_contains($r->url(), 'get-consumption-data') && str_contains($r->url(), 'startDate='.urlencode(monthsAgo(2)->format())) && str_contains($r->url(), 'endDate='.urlencode(monthsAgo(2)->format())))],
    'empty dates' => ['datadis:authorize 12345678Z --from= --to=', fn () => Http::assertSent(fn (Request $r) => str_contains($r->url(), 'new-authorization') && ! str_contains($r->url(), 'startDate') && ! str_contains($r->url(), 'endDate'))],
    'an empty owner' => ['datadis:authorizations --owner=', fn () => Http::assertSent(fn (Request $r) => str_contains($r->url(), 'list-authorization') && ! str_contains($r->url(), 'ownerNif'))],
]);

it('prints text that looks like console formatting as it is, instead of crashing', function (string $command) {
    fakeForCommands();

    [$code, $output] = runCommand($command);

    expect($code)->toBe(1);
    expect($output)->toContain('<fg=foo>');
})->with([
    'an account name' => ["datadis:supplies --account='<fg=foo>'"],
]);

it('prints a table of rows given as a Collection, as the Laravel command does', function () {
    $command = new class extends DatadisCommand
    {
        public $signature = 'datadis:table-probe {--account=}';

        protected function perform(DatadisClient $client): int
        {
            $this->table(['A', 'B'], collect([['1', '<fg=foo>2'], ['3', '4']]));

            return self::SUCCESS;
        }
    };
    $output = new BufferedOutput;
    $command->setLaravel(app());
    $command->run(new ArrayInput([]), $output);

    expect($output->fetch())->toContain('‹fg=foo>2', '| 3');
});

it('prints what Datadis says as it is, even when it looks like console formatting', function () {
    fakeForCommands([
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => '<fg=foo>X</>', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500', 'errorDescription' => '<fg=foo>down</>']]]),
        '*/new-authorization*' => Http::response('<fg=foo>created</>', 200, ['Content-Type' => 'text/plain']),
    ]);

    [$code, $output] = runCommand('datadis:supplies');
    [$authorizeCode, $authorizeOutput] = runCommand('datadis:authorize 12345678Z');

    expect($code)->toBe(0);
    expect($output)->toContain('fg=foo>X', '<fg=foo>down</>');   // the table cell neutralises its <, the warning is raw
    expect($authorizeOutput)->toContain('<fg=foo>created</>');
});

/*
 * Laravel's skeleton installs an output wrapper (laravel/pao) that formats every line once before the console
 * does it again. A plain Testbench output has no such wrapper, so an escape that passes there can still crash in a
 * real application: this output formats once, as the wrapper does, and Laravel builds every command's output from it.
 */

it('survives an output that formats each line once before writing it', function () {
    app()->bind(OutputStyle::class, fn ($app, array $parameters) => new FormatsOnceOutputStyle($parameters['input'], $parameters['output']));
    fakeForCommands([
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => '<fg=foo>X</>', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500', 'errorDescription' => '<fg=foo>down</>']]]),
    ]);

    [$code, $output] = runCommand('datadis:supplies');
    expect($code)->toBe(0);
    expect($output)->toContain('<fg=foo>down</>', 'fg=foo>X');   // the warning raw, the table cell neutralised

    [$bad, $message] = runCommand("datadis:supplies --account='<fg=foo>'");
    expect($bad)->toBe(1);
    expect($message)->toContain('<fg=foo>');
});

it('reads the contract of a supply listed without a point type, which only consumption needs', function () {
    fakeForCommands([
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => []]),
    ]);

    [$contract] = runCommand('datadis:contract '.CUPS);
    [$consumption, $consumptionOutput] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo(2)->format());

    expect($contract)->toBe(0);
    expect($consumption)->toBe(1);
    expect($consumptionOutput)->toContain('cannot see that supply');   // refused by the command, before the client builds anything
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-contract-detail')))->toHaveCount(1);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'get-consumption-data')))->toHaveCount(0);
});

it('says nothing is published only when the answer is empty and no distributor failed', function (array $answer, bool $warns, int $exit) {
    fakeForCommands(['*/get-consumption-data*' => Http::response($answer)]);

    [$code, $output] = runCommand('datadis:consumption '.CUPS.' '.monthsAgo(2)->format());

    expect($code)->toBe($exit);
    expect(str_contains($output, 'Nothing published for that period yet.'))->toBe($warns);
})->with([
    'data' => [['timeCurve' => [['cups' => CUPS, 'date' => '2026/07/01', 'time' => '01:00', 'consumptionKWh' => 0.5, 'obtainMethod' => 'R']], 'distributorError' => []], false, 0],
    'nothing yet' => [['timeCurve' => [], 'distributorError' => []], true, 0],
    'a distributor failed' => [['timeCurve' => [], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500', 'errorDescription' => 'down']]], false, 1],
]);

it('prints the tables of rows Datadis sends without dates, tariffs or powers, instead of failing', function (string $command, string $endpoint, array $answer, array $cells) {
    fakeForCommands([$endpoint => Http::response($answer)]);

    [$code, $output] = runCommand($command);

    expect($code)->toBe(0);
    expect($output)->toContain(...$cells);
})->with([
    'supplies without dates' => [
        'datadis:supplies', '*/get-supplies*',
        ['supplies' => [['cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2']], 'distributorError' => []],
        ['X', 'yes'],
    ],
    'a contract without dates, tariff or power' => [
        'datadis:contract '.CUPS, '*/get-contract-detail*',
        ['contract' => [['cups' => CUPS, 'distributor' => 'D', 'marketer' => 'M']], 'distributorError' => []],
        ['D', 'M', 'open'],
    ],
    'authorizations without dates' => [
        'datadis:authorizations', '*/list-authorization*',
        ['authorizations' => [['id' => '1', 'ownerDocument' => '00000000T', 'requesterDocument' => '12345678Z', 'cups' => CUPS, 'status' => 'ACTIVE']], 'distributorError' => []],
        ['ACTIVE'],
    ],
]);

it('prints every date of the rows it lists', function (string $command, string $endpoint, array $answer, array $cells) {
    fakeForCommands([$endpoint => Http::response($answer)]);

    [$code, $output] = runCommand($command);

    expect($code)->toBe(0);
    expect($output)->toContain(...$cells);
})->with([
    'supplies' => [
        'datadis:supplies', '*/get-supplies*',
        ['supplies' => [['cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2019/03/04', 'validDateTo' => '2031/05/06']], 'distributorError' => []],
        ['2019-03-04', '2031-05-06'],
    ],
    'authorizations' => [
        'datadis:authorizations', '*/list-authorization*',
        ['authorizations' => [['id' => '1', 'ownerDocument' => '00000000T', 'requesterDocument' => '12345678Z', 'cups' => CUPS, 'status' => 'ACTIVE', 'validityDateStart' => '2026/01/07', 'validityDateEnd' => '2027/02/08']], 'distributorError' => []],
        ['2026-01-07', '2027-02-08'],
    ],
]);

it('prints a warning that is an object with a text, and a distributor error without a description', function () {
    fakeForCommands([
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '500']]]),
    ]);

    [$code, $output] = runCommand('datadis:supplies');

    expect($code)->toBe(0);
    expect($output)->toContain('X');   // the table, and a warning with nothing to say, without a failure

    $command = new class extends DatadisCommand
    {
        public $signature = 'datadis:warn-probe {--account=}';

        protected function perform(DatadisClient $client): int
        {
            $this->warn(new class implements Stringable
            {
                public function __toString(): string
                {
                    return 'a <fg=foo>stringable</>';
                }
            });

            return self::SUCCESS;
        }
    };
    $buffer = new BufferedOutput;
    $command->setLaravel(app());
    $command->run(new ArrayInput([]), $buffer);

    expect($buffer->fetch())->toContain('a <fg=foo>stringable</>');
});

it('turns the values of a repeated option into a list, whatever their keys', function () {
    $command = new class extends DatadisCommand
    {
        protected function perform(DatadisClient $client): int
        {
            return self::SUCCESS;
        }

        /** @return list<Cups> */
        public function cupsOf(array $values): array
        {
            return $this->cupsList($values);
        }
    };

    expect(array_keys($command->cupsOf(['first' => CUPS, 'second' => CUPS])))->toBe([0, 1]);
    expect($command->cupsOf([]))->toBe([]);
    expect($command->cupsOf(['not an array' => CUPS]))->toHaveCount(1);
});

it('keeps a backslash at the end of a text, which would escape the closing tag of the line\'s style', function () {
    fakeForCommands([
        '*/get-supplies*' => Http::response(['supplies' => [[
            'cups' => CUPS, 'distributor' => 'X', 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
        ]], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'D', 'errorCode' => '500', 'errorDescription' => 'C:\\temp\\']]]),
    ]);

    [$code, $output] = runCommand('datadis:supplies');

    expect($code)->toBe(0);
    expect($output)->toContain('C:\\temp\\'.PHP_EOL);
});
