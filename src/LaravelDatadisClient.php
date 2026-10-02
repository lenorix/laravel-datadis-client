<?php

namespace Lenorix\LaravelDatadisClient;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the DatadisClient of each configured account on the application's HTTP client and cache.
 *
 * Calls it does not know go to the default account, so the facade reads like the client itself: that
 * includes the operations that change data (authorizations, partner users), which the client offers on purpose.
 *
 * @mixin DatadisClient
 */
class LaravelDatadisClient
{
    /** The account whose credentials `services.datadis` provides. */
    public const string SERVICES_ACCOUNT = 'default';

    /**
     * The collaborators are taken from the container on every call, not kept: a facade holds this
     * object for the whole process, and Http::fake() swaps the HTTP factory after it was built.
     */
    public function __construct(private readonly Application $app) {}

    /**
     * A client for an account of `datadis-client.accounts`; the default one when none is named.
     * Built on every call, after any Http::fake() of a test.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    public function account(?string $name = null): DatadisClient
    {
        $name ??= $this->defaultAccount();
        $settings = $this->settings($name);

        if ($settings === null) {
            throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");
        }

        $store = $this->store();

        return DatadisClient::fromArray(
            $settings,
            http: $this->http($settings),
            tokenCache: $store,
            ledger: new RequestLedger(
                $store,
                new RequestFingerprinter($this->ledgerKey()),
                atomic: new LaravelAtomicStore($store),
            ),
        );
    }

    /**
     * The client of the public open data (aggregated consumption by region, tariff, sector...) for an
     * account. Datadis still asks for an account's token. It shares the login with the private client.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    public function publicApi(?string $name = null): PublicApiClient
    {
        $settings = $this->settings($name ??= $this->defaultAccount())
            ?? throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");

        return new PublicApiClient(DatadisConfig::fromArray($settings), $this->http($settings), tokenCache: $this->store());
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->account()->{$method}(...$parameters);
    }

    /**
     * The settings of an account. The credentials of the account named `default` follow Laravel's
     * convention for third-party services, `config/services.php` (`services.datadis`), and win over the
     * same keys of `datadis-client.accounts.default`, which stay for the other settings. Choosing
     * another account as `datadis-client.default` changes which account is used, never its credentials.
     *
     * @return array<array-key, mixed>|null
     */
    private function settings(string $name): ?array
    {
        $account = $this->config()->get("datadis-client.accounts.{$name}");
        $service = $name === self::SERVICES_ACCOUNT ? $this->config()->get('services.datadis') : null;

        if (! is_array($account) && ! is_array($service)) {
            return null;
        }

        $account = is_array($account) ? $account : [];
        $service = array_filter(is_array($service) ? $service : [], fn ($value) => ! is_string($value) ? $value !== null : trim($value) !== '');

        // Datadis reads `api_version` and `api-version` alike and prefers the first: drop the account's spelling of every key services sets.
        $spelled = array_map(static fn ($key) => str_replace('-', '_', (string) $key), array_keys($service));
        $account = array_filter($account, static fn ($key) => ! in_array(str_replace('-', '_', (string) $key), $spelled, true), ARRAY_FILTER_USE_KEY);

        return array_replace($account, $service);
    }

    private function defaultAccount(): string
    {
        return (string) $this->config()->get('datadis-client.default', 'default');
    }

    /**
     * The package's Guzzle settings, by default on Laravel's handler stack so Http::fake() and Http::assertSent() see every call.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function http(array $settings): ClientInterface
    {
        $config = DatadisConfig::fromArray($settings);

        $options = $this->config()->get('datadis-client.http.options');
        $options = is_array($options) ? $options : [];

        $stack = $this->config()->get('datadis-client.http.stack');

        // Unset: plain Guzzle, so Laravel's events and recorders never see the login password and the token;
        // the test environment keeps Laravel's stack so Http::fake() works.
        if ($stack === null || $stack === '') {
            $stack = $this->app->runningUnitTests() ? 'laravel' : 'guzzle';
        }

        return match ($stack) {
            'laravel' => GuzzleClientFactory::create($config, ['handler' => $this->app->make(Http::class)->buildHandlerStack()] + $options),
            // Plain Guzzle: no Laravel events, recorders or global middleware, which would see the login password and the token.
            'guzzle' => GuzzleClientFactory::create($config, $options),
            default => throw new ConfigurationException('datadis-client.http.stack must be "laravel" or "guzzle", '.(is_string($stack) ? "\"{$stack}\"" : get_debug_type($stack)).' given.'),
        };
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }

    private function store(): Repository
    {
        $name = $this->config()->get('datadis-client.cache.store');

        // Anything but a store name must fail: falling back to the default store could leave the 24 hour guard on a store the workers do not share.
        if ($name !== null && ! is_string($name)) {
            throw new ConfigurationException('datadis-client.cache.store must be the name of a cache store or null, '.get_debug_type($name).' given.');
        }

        return $this->app->make(CacheFactory::class)->store($name === '' ? null : $name);
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
