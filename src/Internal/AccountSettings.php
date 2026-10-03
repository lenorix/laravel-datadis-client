<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient;

/**
 * Where the settings of an account come from. The configuration is read on every call, never kept: the manager lives as long
 * as the process, and a test sets the configuration after it was built.
 *
 * @internal
 */
final class AccountSettings
{
    public function __construct(private readonly Application $app) {}

    /**
     * The settings of an account. The credentials of the account named `default` follow Laravel's
     * convention for third-party services, `config/services.php` (`services.datadis`), and win over the
     * same keys of `datadis-client.accounts.default`, which stay for the other settings. Choosing
     * another account as `datadis-client.default` changes which account is used, never its credentials.
     *
     * @return array<array-key, mixed>|null
     */
    public function find(string $name): ?array
    {
        // A direct lookup, not Laravel's dot notation: an account may be named `tenant.east`.
        $accounts = $this->config()->get('datadis-client.accounts');
        $account = is_array($accounts) ? ($accounts[$name] ?? null) : null;
        $service = $name === LaravelDatadisClient::SERVICES_ACCOUNT ? $this->config()->get('services.datadis') : null;

        if (! is_array($account) && ! is_array($service)) {
            return null;
        }

        $account = is_array($account) ? $account : [];
        $service = array_filter(is_array($service) ? $service : [], fn ($value) => ! is_string($value) ? $value !== null : trim($value) !== '');

        // Datadis reads `api_version` and `api-version` alike and prefers the first: drop the account's `api_version` when services
        // sets it as `api-version` (the other way round, the account's dash spelling loses to services' underscore one by itself).
        $spelled = array_map(static fn ($key) => str_replace('-', '_', (string) $key), array_keys($service));
        $account = array_filter($account, static fn ($key) => ! in_array($key, $spelled, true), ARRAY_FILTER_USE_KEY);

        return array_replace($account, $service);
    }

    /** The name of the account used when none is named (`datadis-client.default`). */
    public function defaultName(): string
    {
        $default = $this->config()->get('datadis-client.default', 'default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'default';
    }

    /** The username the guard keys its entries on: the account's, trimmed and in capitals (the account is known to exist). */
    public function username(?string $name): string
    {
        return DatadisConfig::fromArray($this->find($name ?? $this->defaultName()) ?? [])->username();
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }
}
