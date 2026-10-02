<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Values\MeasurementType;

class ConsumptionCommand extends DatadisCommand
{
    public $signature = 'datadis:consumption
                         {cups : The CUPS of the supply}
                         {month : First month, as YYYY-MM}
                         {--to= : Last month, as YYYY-MM (the first month only if omitted)}
                         {--quarter-hourly : Ask for quarter-hourly readings instead of hourly ones}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'Read the consumption of a supply (Datadis refuses the same query again for 24 hours)';

    protected function perform(DatadisClient $client): int
    {
        $to = $this->option('to');
        $supply = $this->supply($client, $this->argument('cups'));

        $this->warn('Datadis refuses this same query for 24 hours, and counts a refused one: do not run it twice.');

        $result = $client->getConsumptionDataOf(
            $supply,
            $this->month($this->argument('month')),
            is_string($to) && $to !== '' ? $this->month($to) : null,
            $this->option('quarter-hourly') ? MeasurementType::QuarterHourly : MeasurementType::Hourly,
        );

        $this->table(
            ['Date', 'Time', 'kWh', 'Method'],
            array_map(fn ($reading) => [$reading->date, $reading->time, $reading->consumptionKWh, $reading->obtainMethod], $result->records),
        );

        if ($result->isEmpty()) {
            $this->warn($result->isEmptyBecauseOfErrors() ? 'A distributor failed: nothing came back.' : 'Nothing published for that period yet.');
        }

        foreach ($result->distributorErrors as $error) {
            $this->warn((string) $error->errorDescription);
        }

        return self::SUCCESS;
    }
}
