<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient;

use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Lenorix\LaravelDatadisClient\Internal\AccountSettings;
use Lenorix\LaravelDatadisClient\Internal\GuardLedgers;
use Lenorix\LaravelDatadisClient\Internal\HttpClients;
use Lenorix\LaravelDatadisClient\Internal\Importer;
use Lenorix\LaravelDatadisClient\Internal\ReportLevel;
use Lenorix\LaravelDatadisClient\Support\LaravelClock;
use Psr\Clock\ClockInterface;

/**
 * Builds the DatadisClient of each configured account on the application's HTTP client and cache.
 *
 * Calls it does not know go to the default account, so the facade reads like the client itself: that
 * includes the operations that change data (authorizations, partner users), which the client offers on purpose.
 *
 * It only decides what to build and hands the rest to small internal classes (the settings of an account, its HTTP client,
 * the guard's cache and ledger, the imports). A facade holds this object for the whole process, so none of them keeps the
 * configuration, the cache, the HTTP factory or the event dispatcher: each takes them from the container on every call, and
 * `Http::fake()` or `Event::fake()` set after it was built still apply.
 *
 * @mixin DatadisClient
 */
class LaravelDatadisClient
{
    /** The levels the exception handler's logger understands (PSR-3). */
    public const array REPORT_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** The account whose credentials `services.datadis` provides. */
    public const string SERVICES_ACCOUNT = 'default';

    private readonly ClockInterface $clock;

    private readonly AccountSettings $accounts;

    private readonly HttpClients $http;

    private readonly GuardLedgers $guard;

    private readonly ReportLevel $reportLevel;

    private readonly Importer $importer;

    public function __construct(Application $app)
    {
        $this->accounts = new AccountSettings($app);
        $this->http = new HttpClients($app);
        $this->guard = new GuardLedgers($app);
        $this->reportLevel = new ReportLevel($app);
        // The application's time (`now()`, `travelTo()`), not the system's: the guard, the daily range and the token follow it.
        $this->clock = new LaravelClock;
        // The importer is wired with the manager's own, so there is one place where the classes are put together.
        $this->importer = new Importer($this->accounts, $this->guard, $this->reportLevel, $this->clock);
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
            tokenCache: $this->guard->store(),
            ledger: $this->guard->ledger($name),
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

        return new PublicApiClient(DatadisConfig::fromArray($settings), $this->http->make($settings), tokenCache: $this->guard->store(), clock: $this->clock);
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
        return $this->importer->run($account, fn (DatadisClient $client): bool => $client->rememberConsumptionData(
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
        return $this->importer->run($account, fn (DatadisClient $client): bool => $client->rememberMaxPower(
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
        return $this->importer->run($account, fn (DatadisClient $client): bool => $client->rememberReactiveData(
            $at ?? $this->clock->now(), $cups, $distributorCode, $startDate, $endDate,
        ));
    }

    /**
     * The log level of a refused repeated query (`datadis-client.report_level`), or null to leave the handler alone.
     *
     * @throws ConfigurationException when it is not one of the PSR-3 levels: the logger would reject it on every report
     */
    public function reportLevel(): ?string
    {
        return $this->reportLevel->level();
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->account()->{$method}(...$parameters);
    }
}
