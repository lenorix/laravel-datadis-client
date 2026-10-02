<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class SuppliesCommand extends DatadisCommand
{
    public $signature = 'datadis:supplies
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'List the supply points Datadis shows for an account';

    protected function perform(DatadisClient $client): int
    {
        $result = $client->getSupplies();

        $this->table(
            ['CUPS', 'Distributor', 'Point type', 'Valid from', 'Valid to', 'Queryable'],
            array_map(fn ($supply) => [
                $supply->cups,
                $supply->distributor,
                $supply->pointType,
                $supply->validDateFrom?->format('Y-m-d'),
                $supply->validDateTo?->format('Y-m-d'),
                $supply->isQueryable() ? 'yes' : 'no',
            ], $result->records),
        );

        return $this->finish($result);
    }
}
