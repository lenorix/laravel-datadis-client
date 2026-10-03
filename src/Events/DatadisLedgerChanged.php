<?php

namespace Lenorix\LaravelDatadisClient\Events;

use DateTimeImmutable;
use Lenorix\DatadisClient\Guard\LedgerEventKind;

/**
 * Something the 24 hour guard did with a query, to keep a history of what was sent or to audit the guard without reading
 * the cache: a query was claimed (it is going out), released (it never left, so it may be sent again) or remembered (an
 * import of an earlier attempt).
 *
 * It carries no personal data: the account is its name in `datadis-client.accounts` (never the username, which is a NIF),
 * `key` is the guard's opaque keyed hash of the query, the same for the same query, and `endpoint` is the endpoint as it
 * appears in exceptions. It is not sent for a refusal: a query the guard refuses is the `RepetitionWindowException`
 * (and its log line), which says when it is allowed again and which months it asked for.
 */
final readonly class DatadisLedgerChanged
{
    public function __construct(
        public string $account,
        public LedgerEventKind $kind,
        public string $key,
        public DateTimeImmutable $at,
        public ?string $endpoint = null,
    ) {}
}
