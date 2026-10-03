<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Internal;

use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Guard\LedgerEvent;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Lenorix\LaravelDatadisClient\Support\LaravelClock;

/**
 * What the 24 hour guard and the login token live on: Laravel's default cache without its events, the secret that keys the
 * guard, and the ledger that tells its history with an event. The cache, the configuration and the dispatcher are taken from
 * the container on every call, never kept: the manager lives as long as the process, and a test swaps them after it was built.
 *
 * @internal
 */
final class GuardLedgers
{
    private readonly LaravelClock $clock;

    public function __construct(private readonly Application $app)
    {
        $this->clock = new LaravelClock;
    }

    /**
     * Laravel's own cache, the default store (`CACHE_STORE`): the token and the 24 hour guard are not configured apart,
     * so they cannot end up on a store the workers do not share by a setting that is easy to forget.
     *
     * @throws ConfigurationException when the default store is the null driver
     */
    public function store(): Repository
    {
        $repository = $this->app->make(CacheFactory::class)->store();

        // A store that remembers nothing would refuse every guarded query, the first one included, as already sent.
        if ($repository instanceof CacheRepository && $repository->getStore() instanceof NullStore) {
            throw new ConfigurationException('The default cache store is the null driver, which remembers nothing: the 24 hour guard and the login token need a real one (CACHE_STORE=redis or database).');
        }

        // The same store without the event dispatcher: Laravel's cache events (and Telescope's cache watcher)
        // carry the keys and the values, and the token is one of them.
        return $repository instanceof CacheRepository ? new CacheRepository($repository->getStore()) : $repository;
    }

    /**
     * The ledger of an account.
     *
     * @param  string  $account  the name of the account in `datadis-client.accounts`, which the events carry instead of the username
     */
    public function ledger(string $account): RequestLedger
    {
        return new RequestLedger(
            new LaravelAtomicStore($this->store()),
            new RequestFingerprinter($this->secret()),
            $this->clock,
            onChange: function (LedgerEvent $change) use ($account): void {
                // The dispatcher is taken now, not when the ledger is built: Event::fake() swaps it after the client exists.
                // What a listener throws is ignored by the ledger, so a listener never decides whether a query goes.
                $this->app->make(Dispatcher::class)->dispatch(new DatadisLedgerChanged($account, $change->kind, $change->key, $change->at, $change->endpoint, $change->lastAttemptAt, $change->availableAt));
            },
        );
    }

    /**
     * The secret that keys the guard: `datadis-client.ledger.key` as given, or a keyed hash of the application key.
     *
     * @throws ConfigurationException when neither can key the guard
     */
    public function secret(): string
    {
        $config = $this->app->make(Config::class);
        $own = $config->get('datadis-client.ledger.key');

        // Anything but a text must fail: ignoring it would key the guard with the application key without saying so.
        if ($own !== null && ! is_string($own)) {
            throw new ConfigurationException('datadis-client.ledger.key must be a text or null, '.get_debug_type($own).' given.');
        }

        if ($own !== null && $own !== '') {
            return $own;
        }

        $key = $config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new ConfigurationException('Set datadis-client.ledger.key (DATADIS_LEDGER_KEY) or the application key: the 24 hour guard needs a secret.');
        }

        $bytes = str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: $key) : $key;

        if (strlen($bytes) < 16) {
            throw new ConfigurationException('The application key is too short to key the 24 hour guard: set datadis-client.ledger.key (DATADIS_LEDGER_KEY) to a secret of at least 16 bytes.');
        }

        // Derived, not the application key itself: the key of the guard is used for nothing else.
        return hash_hmac('sha256', 'laravel-datadis-client: 24 hour guard', $bytes, true);
    }
}
