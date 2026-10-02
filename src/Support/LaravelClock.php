<?php

namespace Lenorix\LaravelDatadisClient\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * The time of the application: what `now()` gives, so `travelTo()`, `Carbon::setTestNow()` and `Date::use()` move the
 * 24 hour guard, the days of the daily refresh and the life of the token together, as they move the cache.
 */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(Date::now());
    }
}
