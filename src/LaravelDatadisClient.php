<?php

namespace Lenorix\LaravelDatadisClient;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Support\FixedTtlRepository;
use Lenorix\LaravelDatadisClient\Support\LaravelAtomicStore;
use Psr\Clock\ClockInterface;
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
    /** The levels the exception handler's logger understands (PSR-3). */
    public const array REPORT_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

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
        $this->reportLevel();
        $name ??= $this->defaultAccount();
        $settings = $this->settings($name);

        if ($settings === null) {
            throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");
        }

        return DatadisClient::fromArray(
            $settings,
            http: $this->http($settings),
            tokenCache: $this->store(),
            ledger: $this->ledger(),
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
        $settings = $this->settings($name ??= $this->defaultAccount())
            ?? throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");

        return new PublicApiClient(DatadisConfig::fromArray($settings), $this->http($settings), tokenCache: $this->store());
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
     * @throws InvalidArgumentException when a value is not valid, `$at` is in the future or the account is not configured
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
        if (! Supply::isValidPointType($pointType)) {
            throw new InvalidArgumentException('The point type must be a whole number from 1 to 5.');
        }

        $username = $this->username($account);

        return $this->remember($username, $at, $this->monthQuery($cups, $distributorCode, $startDate, $endDate) + [
            'measurementType' => $measurementType->value,
            'pointType' => $pointType,
            // As the client sends it: the account's own NIF is omitted.
            'authorizedNif' => $authorizedNif === null || $authorizedNif->value() === $username ? null : $authorizedNif->value(),
        ]);
    }

    /**
     * Records that a maximum power query was sent before this package kept the record. Datadis keys that query
     * on the CUPS, the distributor code and the months only. See rememberConsumption().
     *
     * @return bool whether it was recorded
     *
     * @throws InvalidArgumentException when a value is not valid, `$at` is in the future or the account is not configured
     */
    public function rememberMaxPower(
        Cups $cups,
        string $distributorCode,
        Month $startDate,
        ?Month $endDate = null,
        ?DateTimeInterface $at = null,
        ?string $account = null,
    ): bool {
        return $this->remember($this->username($account), $at, $this->monthQuery($cups, $distributorCode, $startDate, $endDate));
    }

    /**
     * Records that a reactive energy query was sent before this package kept the record: the same query as the
     * maximum power one. See rememberConsumption().
     *
     * @return bool whether it was recorded
     *
     * @throws InvalidArgumentException when a value is not valid, `$at` is in the future or the account is not configured
     */
    public function rememberReactive(
        Cups $cups,
        string $distributorCode,
        Month $startDate,
        ?Month $endDate = null,
        ?DateTimeInterface $at = null,
        ?string $account = null,
    ): bool {
        return $this->rememberMaxPower($cups, $distributorCode, $startDate, $endDate, $at, $account);
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when the distributor code is not valid
     */
    private function monthQuery(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate): array
    {
        if (! Supply::isValidDistributorCode($distributorCode)) {
            throw new InvalidArgumentException('The distributor code must be 1 to 10 letters, digits, dashes or underscores.');
        }

        if ($endDate !== null && $startDate->isAfter($endDate)) {
            throw new InvalidArgumentException('The first month must not be after the last one.');
        }

        return [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $startDate->format(),
            'endDate' => ($endDate ?? $startDate)->format(),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $query  the query as the client sends it
     */
    private function remember(string $username, ?DateTimeInterface $at, array $query): bool
    {
        $sentAt = DateTimeImmutable::createFromInterface($at ?? new DateTimeImmutable);
        $age = time() - $sentAt->getTimestamp();

        if ($age < -RequestLedger::CLOCK_TOLERANCE_SECONDS) {
            throw new InvalidArgumentException('A query cannot have been sent in the future.');
        }

        if ($age >= RequestLedger::WINDOW_SECONDS) {
            return false;   // out of the window: nothing to protect
        }

        // The guard must hold the newest attempt of the history, whatever order it is given in: that is the one
        // whose window ends last. Each entry lives for what is left of its own window, which is also the time the
        // guard reads from it (a longer life than its time would make the guard, which treats a held key with an
        // expired time as sent now, block the query for hours after Datadis would take it).
        $then = new class($sentAt) implements ClockInterface
        {
            public function __construct(private readonly DateTimeImmutable $at) {}

            public function now(): DateTimeImmutable
            {
                return $this->at;
            }
        };
        // At least one second, since the age is below the window; a time a few minutes ahead (a skewed clock, within
        // the guard's own tolerance) lives that much longer than the window.
        $remaining = RequestLedger::WINDOW_SECONDS - $age;
        $guard = $this->ledger();
        $remembered = $this->ledger($then, $remaining);

        return $this->exclusively($username, $query, function () use ($guard, $remembered, $username, $query, $sentAt): bool {
            $known = $guard->lastAttempt($username, $query);

            if ($known !== null && $known->getTimestamp() >= $sentAt->getTimestamp()) {
                return false;   // the guard already holds this attempt, or a newer one
            }

            // A free key is taken in one step, so a worker that sends now cannot slip in between.
            if ($known === null && $remembered->claim($username, $query) === null) {
                return true;
            }

            // The key is held: by an older attempt, by an entry whose time has expired, or by a worker that has just sent.
            $known = $guard->lastAttempt($username, $query);

            if ($known !== null && $known->getTimestamp() >= $sentAt->getTimestamp()) {
                return false;
            }

            $remembered->record($username, $query);   // overwrite it with this attempt, the newer one

            return true;
        });
    }

    /**
     * Runs the import of one attempt with nobody else importing the same query: two imports that both read the
     * held attempt before either writes would leave the older time, since the overwrite is not a compare and set.
     * A store without locks cannot serialise them: then only one process may import.
     *
     * @template T
     *
     * @param  array<string, string|int|null>  $query
     * @param  Closure(): T  $import
     * @return T
     *
     * @throws LedgerUnavailableException when another import of the same query does not finish
     */
    private function exclusively(string $username, array $query, Closure $import): mixed
    {
        $store = $this->store();
        $provider = $store instanceof CacheRepository ? $store->getStore() : null;

        if (! $provider instanceof LockProvider) {
            return $import();
        }

        $name = 'datadis_import_'.substr((new RequestFingerprinter($this->ledgerKey()))->fingerprint($username, $query), 0, 40);

        try {
            return $provider->lock($name, 30)->block(2, $import);
        } catch (LockTimeoutException $e) {
            throw new LedgerUnavailableException('Another import of the same query did not finish: import the history from one process.', previous: $e);
        }
    }

    /**
     * @param  int|null  $ttlSeconds  how long a new entry lives, when it is not the whole window
     */
    private function ledger(?ClockInterface $clock = null, ?int $ttlSeconds = null): RequestLedger
    {
        $store = $this->store();

        if ($ttlSeconds !== null) {
            // Without the repository's store the lifetime cannot be set: the entry would live a whole window and
            // block the query for hours after Datadis takes it.
            if (! $store instanceof CacheRepository) {
                throw new ConfigurationException('An earlier attempt can only be remembered on a Laravel cache repository, and datadis-client.cache.store gives '.$store::class.'.');
            }

            $store = new FixedTtlRepository($store->getStore(), $ttlSeconds);
        }

        return new RequestLedger(
            $store,
            new RequestFingerprinter($this->ledgerKey()),
            $clock,
            atomic: new LaravelAtomicStore($store, $ttlSeconds),
        );
    }

    /** The username the guard keys its entries on: the account's, trimmed and in capitals. */
    private function username(?string $name): string
    {
        $settings = $this->settings($name ??= $this->defaultAccount())
            ?? throw new InvalidArgumentException("The Datadis account [{$name}] is not configured in services.datadis or datadis-client.accounts.");

        return DatadisConfig::fromArray($settings)->username();
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
        $default = $this->config()->get('datadis-client.default', 'default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * The package's Guzzle settings, on plain Guzzle by default so Laravel's events and recorders never see the login
     * password and the token, or on Laravel's handler stack (`http.stack` = `laravel`, the default in the test environment)
     * so Http::fake() and Http::assertSent() see every call.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function http(array $settings): ClientInterface
    {
        $config = DatadisConfig::fromArray($settings);

        $options = $this->config()->get('datadis-client.http.options');
        // Guzzle's options are named: drop any numeric key.
        $options = is_array($options) ? array_filter($options, is_string(...), ARRAY_FILTER_USE_KEY) : [];

        $stack = $this->config()->get('datadis-client.http.stack');
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

        return $this->withRetries($client);
    }

    /**
     * Retries network failures and 502, 503 and 504 answers, with backoff, only where repeating is harmless: the login,
     * the lists and the reads. Data queries and the calls that change data are never retried.
     */
    private function withRetries(ClientInterface $client): ClientInterface
    {
        $retries = $this->config()->get('datadis-client.http.retries');
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

        $repository = $this->app->make(CacheFactory::class)->store($name === '' ? null : $name);

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
