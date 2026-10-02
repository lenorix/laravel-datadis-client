<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

class SuppliesCommand extends Command
{
    public $signature = 'datadis:supplies
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'List the supply points Datadis shows for an account';

    public function handle(LaravelDatadisClient $datadis): int
    {
        $account = $this->option('account');
        $holder = $this->option('holder');

        try {
            $client = $datadis->account(is_string($account) && $account !== '' ? $account : null);

            if (is_string($holder) && $holder !== '') {
                $client = $client->forHolder(Nif::fromString($holder));
            }

            $result = $client->getSupplies();
        } catch (DatadisException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

        foreach ($result->distributorErrors as $error) {
            $this->warn((string) $error->errorDescription);
        }

        return self::SUCCESS;
    }
}
