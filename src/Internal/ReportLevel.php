<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

/**
 * The log level of a refused repeated query (`datadis-client.report_level`), read on every call.
 *
 * @internal
 */
final class ReportLevel
{
    public function __construct(private readonly Application $app) {}

    /**
     * The level, or null to leave the exception handler alone.
     *
     * @throws ConfigurationException when it is not one of the PSR-3 levels: the logger would reject it on every report
     */
    public function level(): ?string
    {
        $level = $this->app->make(Config::class)->get('datadis-client.report_level');

        if ($level === null || $level === '') {
            return null;
        }

        if (! is_string($level) || ! in_array($level = strtolower(trim($level)), LaravelDatadisClient::REPORT_LEVELS, true)) {
            throw new ConfigurationException('datadis-client.report_level must be null or one of '.implode(', ', LaravelDatadisClient::REPORT_LEVELS).'.');
        }

        return $level;
    }
}
