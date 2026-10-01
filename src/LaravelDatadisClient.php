<?php

namespace Lenorix\LaravelDatadisClient;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;

/**
 * Builds the DatadisClient of each configured account on the application's HTTP client and cache.
 *
 * Calls it does not know go to the default account, so the facade reads like the client itself.
 *
 * @mixin DatadisClient
 */
class LaravelDatadisClient
{
    /**
     * The collaborators are taken from the container on every call, not kept: a facade holds this
     * object for the whole process, and Http::fake() swaps the HTTP factory after it was built.
     */
    public function __construct(private readonly Container $app) {}

    /**
     * A client for an account of `datadis-client.accounts`; the default one when none is named.
     * Built on every call, after any Http::fake() of a test.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    public function account(?string $name = null): DatadisClient
    {
        $name ??= (string) $this->config()->get('datadis-client.default', 'default');
        $settings = $this->settings($name);

        if ($settings === null) {
            throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");
        }

        $store = $this->store();

        return DatadisClient::fromArray(
            $settings,
            // The package's Guzzle settings on Laravel's handler stack, so Http::fake() and Http::assertSent() see every call.
            http: GuzzleClientFactory::create(DatadisConfig::fromArray($settings), ['handler' => $this->app->make(Http::class)->buildHandlerStack()]),
            tokenCache: $store,
            ledger: new RequestLedger(
                $store,
                new RequestFingerprinter($this->ledgerKey()),
                atomic: new LaravelAtomicStore($store),
            ),
        );
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->account()->{$method}(...$parameters);
    }

    /**
     * The settings of an account. The credentials of the default account follow Laravel's convention
     * for third-party services, `config/services.php` (`services.datadis`), and win over the same keys
     * of `datadis-client.accounts.<name>`, which stay for the other settings and for extra accounts.
     *
     * @return array<array-key, mixed>|null
     */
    private function settings(string $name): ?array
    {
        $account = $this->config()->get("datadis-client.accounts.{$name}");
        $service = $name === $this->config()->get('datadis-client.default', 'default') ? $this->config()->get('services.datadis') : null;

        if (! is_array($account) && ! is_array($service)) {
            return null;
        }

        return array_replace(is_array($account) ? $account : [], array_filter(is_array($service) ? $service : [], fn ($value) => $value !== null && $value !== ''));
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }

    private function store(): Repository
    {
        $name = $this->config()->get('datadis-client.cache.store');

        return $this->app->make(CacheFactory::class)->store(is_string($name) && $name !== '' ? $name : null);
    }

    private function ledgerKey(): string
    {
        $key = $this->config()->get('datadis-client.ledger.key') ?: $this->config()->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new ConfigurationException('Set datadis-client.ledger.key (DATADIS_LEDGER_KEY) or the application key: the 24 hour guard needs a secret.');
        }

        return str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: $key) : $key;
    }
}
