<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Support;

use Illuminate\Contracts\Cache\Repository;
use Lenorix\DatadisClient\Guard\AtomicLedgerStore;

/**
 * The store of the 24 hour guard on a Laravel cache store: reads, writes and, with the add() of the store, records a
 * query only if absent, so checking and recording are one step and two workers cannot both send it.
 */
final class LaravelAtomicStore implements AtomicLedgerStore
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function set(string $key, int $value, int $ttlSeconds): bool
    {
        return $this->cache->put($key, $value, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        // A key that is not there counts as removed, and some stores answer false for it.
        return $this->cache->forget($key) || ! $this->cache->has($key);
    }

    public function add(string $key, int $value, int $ttlSeconds): bool
    {
        return $this->cache->add($key, $value, $ttlSeconds);
    }
}
