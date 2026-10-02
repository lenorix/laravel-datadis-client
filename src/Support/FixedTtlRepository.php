<?php

namespace Lenorix\LaravelDatadisClient\Support;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache repository that stores everything for one fixed lifetime, whatever the caller asks for.
 *
 * The 24 hour guard always asks for the whole window. An attempt made earlier must live only for what is left
 * of its window, and the guard cannot be told that, so the store it writes to says it.
 */
final class FixedTtlRepository extends Repository
{
    /**
     * @param  \UnitEnum|array<array-key, mixed>|string  $key
     * @param  mixed  $value
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl  ignored: the lifetime is the one given at construction
     * @return bool
     */
    public function put($key, $value, $ttl = null)
    {
        return parent::put($key, $value, $this->ttlSeconds);
    }

    public function __construct(Store $store, private readonly int $ttlSeconds)
    {
        parent::__construct($store);
    }
}
