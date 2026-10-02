<?php

namespace Lenorix\LaravelDatadisClient\Support;

use Illuminate\Contracts\Cache\Repository;
use Lenorix\DatadisClient\Guard\AtomicStore;

/**
 * Lets the 24 hour guard record a query only if absent, with the add() of a Laravel cache store.
 */
final class LaravelAtomicStore implements AtomicStore
{
    /**
     * @param  int|null  $ttlSeconds  a lifetime that replaces the one the guard asks for: for an attempt that was
     *                                made earlier, only what is left of its window
     */
    public function __construct(private readonly Repository $cache, private readonly ?int $ttlSeconds = null) {}

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        return $this->cache->add($key, $value, $this->ttlSeconds ?? $ttlSeconds);
    }
}
