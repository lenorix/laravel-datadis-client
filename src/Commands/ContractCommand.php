<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class ContractCommand extends DatadisCommand
{
    public $signature = 'datadis:contract
                         {cups : The CUPS of the supply}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'Show the contract of a supply: access tariff, contracted power and dates';

    protected function perform(DatadisClient $client): int
    {
        $result = $client->getContractDetailOf($this->supply($client, $this->argument('cups')));

        $this->table(
            ['Distributor', 'Marketer', 'Access tariff', 'Contracted power (kW)', 'From', 'To'],
            array_map(fn ($contract) => [
                $contract->distributor,
                $contract->marketer,
                $contract->tariff()->name ?? $contract->accessFare,
                implode(' / ', array_map(fn ($kw) => (string) $kw, $contract->contractedPowerkW)),
                $contract->startDate?->format('Y-m-d'),
                $contract->endDate?->format('Y-m-d') ?? 'open',
            ], $result->records),
        );

        foreach ($result->distributorErrors as $error) {
            $this->warn((string) $error->errorDescription);
        }

        return self::SUCCESS;
    }
}
