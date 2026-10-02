<?php

namespace Lenorix\LaravelDatadisClient\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

/**
 * The base of the artisan commands: the account and holder options, and a clean failure for what Datadis
 * or the input gets wrong.
 */
abstract class DatadisCommand extends Command
{
    public function handle(LaravelDatadisClient $datadis): int
    {
        try {
            return $this->perform($this->client($datadis));
        } catch (DatadisException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    abstract protected function perform(DatadisClient $client): int;

    private function client(LaravelDatadisClient $datadis): DatadisClient
    {
        $account = $this->option('account');
        $holder = $this->option('holder');

        $client = $datadis->account(is_string($account) && $account !== '' ? $account : null);

        return is_string($holder) && $holder !== '' ? $client->forHolder(Nif::fromString($holder)) : $client;
    }

    /**
     * The supply of a CUPS, which must be one the account can query.
     *
     * @throws InvalidArgumentException when the account has no such supply, or its codes are unusable
     */
    protected function supply(DatadisClient $client, mixed $cups): Supply
    {
        $supply = $client->findSupply(Cups::fromString(is_string($cups) ? $cups : ''));

        if ($supply === null || ! $supply->isQueryable()) {
            throw new InvalidArgumentException('This account cannot see that supply, or Datadis gave no usable codes for it.');
        }

        return $supply;
    }

    /**
     * @throws InvalidArgumentException when it is not YYYY-MM or YYYY/MM
     */
    protected function month(mixed $value): Month
    {
        return Month::fromString(str_replace('-', '/', is_string($value) ? $value : ''));
    }

    /**
     * @throws InvalidArgumentException when it is given and is not YYYY-MM-DD
     */
    protected function date(mixed $value, string $option): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$option} must be a date as YYYY-MM-DD.");
        }

        return $date;
    }

    /**
     * @return list<Cups>
     *
     * @throws InvalidArgumentException when a CUPS is malformed
     */
    protected function cupsList(mixed $values): array
    {
        return array_map(fn (mixed $cups) => Cups::fromString(is_string($cups) ? $cups : ''), is_array($values) ? array_values($values) : []);
    }
}
