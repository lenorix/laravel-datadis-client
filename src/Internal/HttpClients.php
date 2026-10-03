<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Psr\Http\Client\ClientInterface;

/**
 * The HTTP client of an account. The configuration and the HTTP factory are taken from the container on every call, never
 * kept: the manager lives as long as the process, and `Http::fake()` swaps the factory after it was built.
 *
 * @internal
 */
final class HttpClients
{
    private readonly Retries $retries;

    public function __construct(private readonly Application $app)
    {
        $this->retries = new Retries($app);
    }

    /**
     * The package's Guzzle settings, on plain Guzzle by default so Laravel's events and recorders never see the login
     * password and the token, or on Laravel's handler stack (`http.stack` = `laravel`, the default in the test environment)
     * so Http::fake() and Http::assertSent() see every call.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @throws ConfigurationException when the stack, the options or the retries are wrong
     */
    public function make(array $settings): ClientInterface
    {
        $config = DatadisConfig::fromArray($settings);
        $settingsOf = $this->app->make(Config::class);

        $options = $settingsOf->get('datadis-client.http.options');
        // Guzzle's options are named: only string keys, which is also what the client factory's type says (a numeric key is harmless to Guzzle).
        $options = is_array($options) ? array_filter($options, is_string(...), ARRAY_FILTER_USE_KEY) : [];

        // Guzzle's `debug` prints each request, with the login's password, to the output.
        if (! empty($options['debug'])) {
            throw new ConfigurationException('datadis-client.http.options.debug would print the login request, with the password, to the output.');
        }

        $stack = $settingsOf->get('datadis-client.http.stack');
        $stack = is_string($stack) ? strtolower(trim($stack)) : $stack;

        // Unset: plain Guzzle, so Laravel's events and recorders never see the login password and the token;
        // the test environment keeps Laravel's stack so Http::fake() works.
        if ($stack === null || $stack === '') {
            $stack = $this->app->runningUnitTests() ? 'laravel' : 'guzzle';
        }

        if ($stack === 'guzzle' && $this->app->runningUnitTests() && ! isset($options['handler'])) {
            throw new ConfigurationException('datadis-client.http.stack is "guzzle" in the test environment: Http::fake() would not apply and the test would reach Datadis. Leave DATADIS_HTTP_STACK unset in tests, or give datadis-client.http.options.handler a mock handler.');
        }

        $client = match ($stack) {
            'laravel' => GuzzleClientFactory::create($config, ['handler' => $this->app->make(Http::class)->buildHandlerStack()] + $options),
            // Plain Guzzle: no Laravel events, recorders or global middleware, which would see the login password and the token.
            'guzzle' => GuzzleClientFactory::create($config, $options),
            default => throw new ConfigurationException('datadis-client.http.stack must be "laravel" or "guzzle", '.(is_string($stack) ? "\"{$stack}\"" : get_debug_type($stack)).' given.'),
        };

        return $this->retries->wrap($client);
    }
}
