<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Psr\Log\LoggerInterface;

it('reports a refused repeat as a warning, not an error', function () {
    fakeDatadis();
    $supply = app(DatadisClient::class)->findSupply(Cups::fromString(CUPS));
    app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());

    try {
        app(DatadisClient::class)->getConsumptionDataOf($supply, monthsAgo());
    } catch (RepetitionWindowException $e) {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldNotReceive('error');
        app()->instance(LoggerInterface::class, $logger);

        app(ExceptionHandler::class)->report($e);
    }

    expect($e)->toBeInstanceOf(RepetitionWindowException::class);
});

it('leaves the exception handler alone when report_level is null', function () {
    config()->set('datadis-client.report_level', null);
    app()->forgetInstance(ExceptionHandler::class);
    $handler = app(ExceptionHandler::class);

    $levels = (fn () => $this->levels)->call($handler);

    expect($levels)->not->toHaveKey(RepetitionWindowException::class);
});

it('sets the configured level on the exception handler', function () {
    config()->set('datadis-client.report_level', 'info');
    app()->forgetInstance(ExceptionHandler::class);
    $handler = app(ExceptionHandler::class);

    expect((fn () => $this->levels)->call($handler))->toHaveKey(RepetitionWindowException::class, 'info');
});

it('refuses a report level the logger would reject, without breaking error reporting', function (mixed $level) {
    $file = sys_get_temp_dir().'/datadis-report-'.bin2hex(random_bytes(4)).'.log';
    config()->set('logging.default', 'single');
    config()->set('logging.channels.single', ['driver' => 'single', 'path' => $file]);
    config()->set('datadis-client.report_level', $level);
    app()->forgetInstance(ExceptionHandler::class);

    expect(fn () => app(Manager::class)->account())->toThrow(ConfigurationException::class, 'report_level');
    expect(fn () => app(Manager::class)->publicApi())->toThrow(ConfigurationException::class, 'report_level');

    // The handler still reports: the bad level is ignored there.
    app(ExceptionHandler::class)->report(new RepetitionWindowException('repeat'));
    expect(is_file($file) ? file_get_contents($file) : '')->toContain('repeat');
    @unlink($file);
})->with(['typo' => 'warn', 'number' => 3, 'array' => [['warning']]]);

it('takes the report level in any case', function () {
    config()->set('datadis-client.report_level', ' WARNING ');

    expect(app(Manager::class)->reportLevel())->toBe('warning');
    expect(app(Manager::class)->account())->toBeInstanceOf(DatadisClient::class);
});

it('accepts every level of the logger, in any case, and none that is not one', function () {
    foreach (Manager::REPORT_LEVELS as $level) {
        config()->set('datadis-client.report_level', strtoupper($level));
        expect(app(Manager::class)->reportLevel())->toBe($level);
    }

    expect(Manager::REPORT_LEVELS)->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
});

it('has no report level to set when it is null or empty', function (mixed $level) {
    config()->set('datadis-client.report_level', $level);

    expect(app(Manager::class)->reportLevel())->toBeNull();
})->with([null, '']);
