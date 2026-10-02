<?php

namespace Lenorix\LaravelDatadisClient\Tests\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * A store that cannot lock, and keeps its own time: only the Store contract, in an array, with lifetimes read from the
 * system clock (as Redis reads its server's), so `travelTo()` does not reach it.
 */
class NoLockStore implements Store
{
    /** @var array<string, array{mixed, int}> */
    private array $items = [];

    public function get($key)
    {
        return isset($this->items[$key]) && $this->items[$key][1] > time() ? $this->items[$key][0] : null;
    }

    public function many(array $keys)
    {
        return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = [$value, time() + $seconds];

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key][0] = ($this->get($key) ?? 0) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 315360000);
    }

    public function touch($key, $seconds)
    {
        if (! isset($this->items[$key])) {
            return false;
        }

        $this->items[$key][1] = time() + $seconds;

        return true;
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}
