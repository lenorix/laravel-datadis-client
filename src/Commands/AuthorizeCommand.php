<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class AuthorizeCommand extends DatadisCommand
{
    public $signature = 'datadis:authorize
                         {nif : The NIF, NIE or CIF of the person to let read your supplies}
                         {--cups=* : Only these supplies (all of them if omitted)}
                         {--from= : Start of the period, as YYYY-MM-DD}
                         {--to= : End of the period, as YYYY-MM-DD}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'Let a third party read your supplies (changes data on Datadis)';

    protected function perform(DatadisClient $client): int
    {
        $answer = $client->newAuthorization(
            $this->nif($this->argument('nif')),
            $this->date($this->option('from'), 'from'),
            $this->date($this->option('to'), 'to'),
            ...$this->cupsList($this->option('cups')),
        );

        $this->line($answer);

        return self::SUCCESS;
    }
}
