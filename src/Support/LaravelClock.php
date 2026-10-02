<?php

namespace Lenorix\LaravelDatadisClient\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * The time of the application: what `now()` gives, so `travelTo()`, `Carbon::setTestNow()` and `Date::use()` move the
 * 24 hour guard, the days of the daily refresh and the life of the token together. A cache whose expiry follows `now()` (array, file, database) moves with them; one with its own clock (Redis, Memcached) keeps a held key, and the guard still refuses it.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(Date::now());
    }
}
