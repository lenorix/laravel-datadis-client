<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Commands\Concerns\PrintsUntrustedText;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

/**
 * The base of the artisan commands: the account and holder options, and a clean failure for what Datadis
 * or the input gets wrong.
 */
abstract class DatadisCommand extends Command
{
    use PrintsUntrustedText;

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

    /**
     * The NIF of the holder the command reads for. None by default: the commands that act for the account itself
     * (the authorizations) must not take one, which would suggest the operation is made for that holder.
     */
    protected function holderOption(): mixed
    {
        return null;
    }

    /**
     * Prints what the distributors reported and gives the exit code of a read.
     *
     * A run that got no data because a distributor failed is a failure: a script that looks only at the exit
     * code must not take it for a success. A distributor error beside real data is a warning, and the exit code
     * stays 0. An empty answer without errors (nothing published yet) is not a failure either.
     *
     * @template T
     *
     * @param  ApiResult<T>  $result
     */
    protected function finish(ApiResult $result): int
    {
        foreach ($result->distributorErrors as $error) {
            $this->warn((string) $error->errorDescription);
        }

        if ($result->isEmptyBecauseOfErrors()) {
            $this->error('A distributor failed and no data came back.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function client(LaravelDatadisClient $datadis): DatadisClient
    {
        $account = $this->option('account');
        $holder = $this->holderOption();

        $client = $datadis->account(is_string($account) && $account !== '' ? $account : null);

        return is_string($holder) && $holder !== '' ? $client->forHolder(Nif::fromString($holder)) : $client;
    }

    /**
     * The supply of a CUPS, which must be one the account can query (with a point type, when the call needs it).
     *
     * @throws InvalidArgumentException when the account has no such supply, or its codes are unusable
     */
    protected function supply(DatadisClient $client, Cups $cups, bool $needsPointType = false): Supply
    {
        $supply = $client->findSupply($cups);

        // Only consumption takes the point type: the contract, the maximum power and the reactive energy do not.
        if ($supply === null || ! Supply::isValidDistributorCode($supply->distributorCode) || ($needsPointType && ! Supply::isValidPointType($supply->pointType))) {
            throw new InvalidArgumentException('This account cannot see that supply, or Datadis gave no usable codes for it.');
        }

        return $supply;
    }

    /**
     * @throws InvalidArgumentException when it is not a CUPS
     */
    protected function cups(mixed $value): Cups
    {
        return Cups::fromString(is_string($value) ? $value : '');
    }

    /**
     * @throws InvalidArgumentException when it is not a valid NIF, NIE or CIF
     */
    protected function nif(mixed $value): Nif
    {
        return Nif::fromString(is_string($value) ? $value : '');
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
        return array_map(fn (mixed $cups) => $this->cups($cups), is_array($values) ? array_values($values) : []);
    }
}
