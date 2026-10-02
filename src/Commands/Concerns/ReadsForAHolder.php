<?php

namespace Lenorix\LaravelDatadisClient\Commands\Concerns;

/**
 * For the commands that read supplies and data: they take --holder, the NIF of someone who authorized the account.
 * The commands that manage authorizations act for the account itself and do not use it.
 */
trait ReadsForAHolder
{
    protected function holderOption(): mixed
    {
        return $this->option('holder');
    }
}
