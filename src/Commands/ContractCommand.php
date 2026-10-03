<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;
use Lenorix\LaravelDatadisClient\Commands\Concerns\ReadsForAHolder;

class ContractCommand extends DatadisCommand
{
    use ReadsForAHolder;

    public $signature = 'datadis:contract
                         {cups : The CUPS of the supply}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'Show the contract of a supply: access tariff, contracted power and dates';

    protected function perform(DatadisClient $client): int
    {
        $result = $client->getContractDetailOf($this->supply($client, $this->cups($this->argument('cups'))));

        $this->table(
            ['Distributor', 'Marketer', 'Access tariff', 'Contracted power (kW)', 'From', 'To'],
            array_map(fn ($contract) => [
                $contract->distributor,
                $contract->marketer,
                $contract->tariff()->name ?? $contract->accessFare,
                implode(' / ', $contract->contractedPowerkW),   // a missing period is an empty place
                $contract->startDate?->format('Y-m-d'),
                $contract->endDate?->format('Y-m-d') ?? 'open',
            ], $result->records),
        );

        return $this->finish($result);
    }
}
