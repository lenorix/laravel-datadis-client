<?php

namespace Lenorix\LaravelDatadisClient\Support;

use Illuminate\Contracts\Cache\Repository;
use Lenorix\DatadisClient\Guard\AtomicStore;

/**
 * Lets the 24 hour guard record a query only if absent, with the add() of a Laravel cache store.
 */
final class LaravelAtomicStore implements AtomicStore
{
    public function __construct(private readonly Repository $cache) {}

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        return $this->cache->add($key, $value, $ttlSeconds);
    }
}
