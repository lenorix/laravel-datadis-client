<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class CancelAuthorizationCommand extends DatadisCommand
{
    public $signature = 'datadis:authorization:cancel
                         {nif : The NIF, NIE or CIF of the person who loses access}
                         {--cups=* : Only these supplies (all of them if omitted)}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'Take a third party\'s access to your supplies away (changes data on Datadis)';

    protected function perform(DatadisClient $client): int
    {
        $this->line($client->cancelAuthorization($this->nif($this->argument('nif')), ...$this->cupsList($this->option('cups'))));

        return self::SUCCESS;
    }
}
