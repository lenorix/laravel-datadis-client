<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient;

use Closure;
use DateTimeInterface;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Guard\LedgerEvent;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Events\DatadisLedgerChanged;
use Lenorix\LaravelDatadisClient\Internal\AccountSettings;
use Lenorix\LaravelDatadisClient\Internal\HttpClients;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Lenorix\LaravelDatadisClient\Support\LaravelClock;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
    /** The levels the exception handler's logger understands (PSR-3). */
    public const array REPORT_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** The account whose credentials `services.datadis` provides. */
    public const string SERVICES_ACCOUNT = 'default';

    /**
     * The collaborators are taken from the container on every call, not kept: a facade holds this
     * object for the whole process, and Http::fake() swaps the HTTP factory after it was built.
     */
    private readonly ClockInterface $clock;

    private readonly AccountSettings $accounts;

    private readonly HttpClients $http;

    public function __construct(private readonly Application $app)
    {
        $this->accounts = new AccountSettings($app);
        $this->http = new HttpClients($app);
        // The application's time (`now()`, `travelTo()`), not the system's: the guard, the daily range and the token follow it.
        $this->clock = new LaravelClock;
    }

    /**
     * A client for an account of `datadis-client.accounts`; the default one when none is named.
     * Built on every call, after any Http::fake() of a test.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    public function account(?string $name = null): DatadisClient
    {
        $this->reportLevel();
        $name ??= $this->accounts->defaultName();
        $settings = $this->accounts->find($name);

        if ($settings === null) {
            throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");
        }

        return DatadisClient::fromArray(
            $settings,
            http: $this->http->make($settings),
            tokenCache: $this->store(),
            ledger: $this->ledger($name),
            clock: $this->clock,
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
        $this->reportLevel();
        $settings = $this->accounts->find($name ??= $this->accounts->defaultName())
            ?? throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");

        return new PublicApiClient(DatadisConfig::fromArray($settings), $this->http->make($settings), tokenCache: $this->store(), clock: $this->clock);
    }

    /**
     * Records that a consumption query was sent before this package kept the record, so that the 24 hour guard
     * knows it: for the moment you switch from a record of your own (a table, say) to this package's.
     *
     * Give the query as it was sent (the point type, the measurement type and the holder, if you used one) and,
     * in `$at`, when. It is remembered for what is left of the window, so a query sent 23 hours ago blocks a
     * repeat for one more hour and ten minutes. An attempt older than the window is not recorded. The guard keeps
     * the newest attempt of each query, whatever order a history is given in: a held attempt that is older is
     * replaced, and one at the same time or newer is left alone. Do it before any worker sends a guarded query
     * with the new client, with the workers paused.
     *
     * @return bool whether it was recorded
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$at` is more than ten minutes in the future
     * @throws ConfigurationException when the account's settings or the cache are wrong (the account is built first)
     * @throws LedgerUnavailableException when the guard's store fails, or another import does not finish
     */
    public function rememberConsumption(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
        ?DateTimeInterface $at = null,
        ?string $account = null,
    ): bool {
        return $this->exclusively($account, fn (DatadisClient $client): bool => $client->rememberConsumptionData(
            $at ?? $this->clock->now(), $cups, $distributorCode, $pointType, $startDate, $endDate, $measurementType, $authorizedNif,
        ));
    }

    /**
     * Records that a maximum power query was sent before this package kept the record. Datadis keys that query
     * on the CUPS, the distributor code and the months only. See rememberConsumption().
     *
     * @return bool whether it was recorded
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$at` is more than ten minutes in the future
     * @throws LedgerUnavailableException when the guard's store fails, or another import does not finish
     */
    public function rememberMaxPower(
        Cups $cups,
        string $distributorCode,
        Month $startDate,
        ?Month $endDate = null,
        ?DateTimeInterface $at = null,
        ?string $account = null,
    ): bool {
        return $this->exclusively($account, fn (DatadisClient $client): bool => $client->rememberMaxPower(
            $at ?? $this->clock->now(), $cups, $distributorCode, $startDate, $endDate,
        ));
    }

    /**
     * Records that a reactive energy query was sent before this package kept the record: the same query as the
     * maximum power one. See rememberConsumption().
     *
     * @return bool whether it was recorded
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$at` is more than ten minutes in the future
     * @throws UnsupportedOperationException when the account uses API v1, which has no reactive data
     * @throws LedgerUnavailableException when the guard's store fails, or another import does not finish
     */
    public function rememberReactive(
        Cups $cups,
        string $distributorCode,
        Month $startDate,
        ?Month $endDate = null,
        ?DateTimeInterface $at = null,
        ?string $account = null,
    ): bool {
        return $this->exclusively($account, fn (DatadisClient $client): bool => $client->rememberReactiveData(
            $at ?? $this->clock->now(), $cups, $distributorCode, $startDate, $endDate,
        ));
    }

    /**
     * Runs an import with nobody else importing for the account: the ledger keeps the newest attempt of a query, but
     * two imports that both read the held attempt before either writes would leave the older time, since the
     * overwrite is not a compare and set. One lock for the account, named from a keyed hash, never from the NIF.
     * A store without locks cannot serialise them: then only one process may import.
     *
     * @template T
     *
     * @param  Closure(DatadisClient): T  $import
     * @return T
     *
     * @throws LedgerUnavailableException when another import of the account does not finish
     */
    private function exclusively(?string $account, Closure $import): mixed
    {
        $client = $this->clientForImport($account);
        $store = $this->store();
        $provider = $store instanceof CacheRepository ? $store->getStore() : null;

        if (! $provider instanceof LockProvider) {
            return $import($client);
        }

        try {
            return $provider->lock($this->importLockName($this->accounts->username($account)), 30)->block(2, fn () => $import($client));
        } catch (LockTimeoutException $e) {
            throw new LedgerUnavailableException('Another import did not finish: import the history from one process.', previous: $e);
        }
    }

    /**
     * The client an import needs: the account's settings and the ledger, and no HTTP stack, since nothing is sent. A wrong
     * `http` setting must not stop an import, and no request can leave from it.
     *
     * @throws InvalidArgumentException when the account is not configured
     * @throws ConfigurationException when its settings are wrong
     */
    private function clientForImport(?string $name): DatadisClient
    {
        $this->reportLevel();
        $settings = $this->accounts->find($name ?? $this->accounts->defaultName())
            ?? throw new InvalidArgumentException('The Datadis account ['.($name ?? $this->accounts->defaultName()).'] is not configured in services.datadis or datadis-client.accounts.');

        // A client that cannot send: an import never reaches Datadis, whatever the HTTP settings or the test environment say.
        $mute = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new LogicException('An import never sends a request.');
            }
        };

        return DatadisClient::fromArray($settings, http: $mute, ledger: $this->ledger($name ?? $this->accounts->defaultName()), clock: $this->clock);
    }

    private function importLockName(string $username): string
    {
        return 'datadis_import_'.substr(hash_hmac('sha256', $username, $this->ledgerKey()), 0, 40);
    }

    /**
     * @param  string  $account  the name of the account in `datadis-client.accounts`, which the events carry instead of the username
     */
    private function ledger(string $account): RequestLedger
    {
        return new RequestLedger(
            new LaravelAtomicStore($this->store()),
            new RequestFingerprinter($this->ledgerKey()),
            $this->clock,
            onChange: function (LedgerEvent $change) use ($account): void {
                // The dispatcher is taken now, not when the ledger is built: Event::fake() swaps it after the client exists.
                // What a listener throws is ignored by the ledger, so a listener never decides whether a query goes.
                $this->app->make(Dispatcher::class)->dispatch(new DatadisLedgerChanged($account, $change->kind, $change->key, $change->at, $change->endpoint, $change->lastAttemptAt, $change->availableAt));
            },
        );
    }

    /**
     * The log level of a refused repeated query (`datadis-client.report_level`), or null to leave the handler alone.
     *
     * @throws ConfigurationException when it is not one of the PSR-3 levels: the logger would reject it on every report
     */
    public function reportLevel(): ?string
    {
        $level = $this->config()->get('datadis-client.report_level');

        if ($level === null || $level === '') {
            return null;
        }

        if (! is_string($level) || ! in_array($level = strtolower(trim($level)), self::REPORT_LEVELS, true)) {
            throw new ConfigurationException('datadis-client.report_level must be null or one of '.implode(', ', self::REPORT_LEVELS).'.');
        }

        return $level;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->account()->{$method}(...$parameters);
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }

    /**
     * Laravel's own cache, the default store (`CACHE_STORE`): the token and the 24 hour guard are not configured apart,
     * so they cannot end up on a store the workers do not share by a setting that is easy to forget.
     */
    private function store(): Repository
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

    private function ledgerKey(): string
    {
        $own = $this->config()->get('datadis-client.ledger.key');

        // Anything but a text must fail: ignoring it would key the guard with the application key without saying so.
        if ($own !== null && ! is_string($own)) {
            throw new ConfigurationException('datadis-client.ledger.key must be a text or null, '.get_debug_type($own).' given.');
        }

        if ($own !== null && $own !== '') {
            return $own;
        }

        $key = $this->config()->get('app.key');

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
