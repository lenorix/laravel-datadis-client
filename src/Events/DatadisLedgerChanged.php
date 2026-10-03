<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Events;

use DateTimeImmutable;
use Lenorix\DatadisClient\Guard\LedgerEventKind;

/**
 * Something the 24 hour guard did with a query, to keep a history of what was sent or to audit the guard without reading
 * the cache: a query was claimed (it is going out), released (it never left, so it may be sent again), remembered (an
 * import of an earlier attempt) or refused (an attempt within the window holds it).
 *
 * For a refusal, `at` is when the call was refused, `lastAttemptAt` the attempt that holds the query and `availableAt` when
 * it may go again, the same as the `RepetitionWindowException`; both are null for every other kind.
 *
 * It carries no personal data: the account is its name in `datadis-client.accounts` (never the username, which is a NIF),
 * `key` is the guard's opaque keyed hash of the query, the same for the same query, and `endpoint` is the endpoint as it
 * appears in exceptions. A refusal Datadis itself makes (a 429) is not an event: it is the exception.
 */
final readonly class DatadisLedgerChanged
{
    public function __construct(
        public string $account,
        public LedgerEventKind $kind,
        public string $key,
        public DateTimeImmutable $at,
        public ?string $endpoint = null,
        public ?DateTimeImmutable $lastAttemptAt = null,
        public ?DateTimeImmutable $availableAt = null,
    ) {}
}
