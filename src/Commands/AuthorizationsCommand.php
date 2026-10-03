<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Commands;

use Lenorix\DatadisClient\DatadisClient;

class AuthorizationsCommand extends DatadisCommand
{
    public $signature = 'datadis:authorizations
                         {--owner= : Only the authorizations given by this owner (a NIF, NIE or CIF)}
                         {--account= : Account of datadis-client.accounts, the default one if omitted}';

    public $description = 'List who can read which supplies of the account (it acts for the account itself, never for a holder)';

    protected function perform(DatadisClient $client): int
    {
        $owner = $this->option('owner');

        $result = $client->listAuthorization(is_string($owner) && $owner !== '' ? $this->nif($owner) : null);

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

        return $this->finish($result);
    }
}
