<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Http\RetryingClient;
use Psr\Http\Client\ClientInterface;

/**
 * The retry policy of `datadis-client.http.retries`, read on every call.
 *
 * @internal
 */
final class Retries
{
    public function __construct(private readonly Application $app) {}

    /**
     * Retries network failures and 502, 503 and 504 answers, with backoff, only where repeating is harmless: the login,
     * the lists and the reads. Data queries and the calls that change data are never retried.
     *
     * @throws ConfigurationException when a limit or a delay is not what can work
     */
    public function wrap(ClientInterface $client): ClientInterface
    {
        $retries = $this->app->make(Config::class)->get('datadis-client.http.retries');
        $retries = is_array($retries) ? $retries : [];

        $max = $this->whole($retries, 'max', 2);
        $base = $this->whole($retries, 'base_delay_ms', 1000);
        $longest = $this->whole($retries, 'max_delay_ms', 30000);

        if ($max < 0 || $max > 10) {
            throw new ConfigurationException('datadis-client.http.retries.max must be between 0 and 10.');
        }

        if ($max === 0) {
            return $client;
        }

        if ($base < 1 || $longest < $base) {
            throw new ConfigurationException('datadis-client.http.retries delays must be positive and max_delay_ms not below base_delay_ms.');
        }

        return new RetryingClient($client, $max, $base, $longest);
    }

    /**
     * A whole number of `http.retries`, or its default when it is not set (an empty `.env` value is not set).
     *
     * @param  array<array-key, mixed>  $retries
     */
    private function whole(array $retries, string $key, int $default): int
    {
        $value = $retries[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            return (int) $value;
        }

        throw new ConfigurationException("datadis-client.http.retries.{$key} must be a whole number.");
    }
}
