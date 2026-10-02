<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class AuthorizationsCommand extends DatadisCommand
{
    public $signature = 'datadis:authorizations
                         {--account= : Account of datadis-client.accounts, the default one if omitted}
                         {--holder= : NIF of a holder who authorized the account}';

    public $description = 'List who can read which supplies of the account';

    protected function perform(DatadisClient $client): int
    {
        $result = $client->listAuthorization();

        $this->table(
            ['Owner', 'Requester', 'CUPS', 'Status', 'From', 'To'],
            array_map(fn ($authorization) => [
                $authorization->ownerDocument,
                $authorization->requesterDocument,
                $authorization->cups,
                $authorization->status,
                $authorization->validityDateStart?->format('Y-m-d'),
                $authorization->validityDateEnd?->format('Y-m-d'),
            ], $result->records),
        );

        return self::SUCCESS;
    }
}
