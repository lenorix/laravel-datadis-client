<?php

namespace Lenorix\LaravelDatadisClient\Facades;

use Illuminate\Support\Facades\Facade;
use Lenorix\DatadisClient\DatadisClient;

/**
 * @method static DatadisClient account(?string $name = null)
 *
 * @mixin DatadisClient
 *
 * @see \Lenorix\LaravelDatadisClient\LaravelDatadisClient
 */
class LaravelDatadisClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lenorix\LaravelDatadisClient\LaravelDatadisClient::class;
    }
}
