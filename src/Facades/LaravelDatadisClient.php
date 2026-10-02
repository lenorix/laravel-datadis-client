<?php

namespace Lenorix\LaravelDatadisClient\Facades;

use DateTimeInterface;
use Illuminate\Support\Facades\Facade;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Http\Endpoint;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/**
 * @method static DatadisClient account(?string $name = null)
 * @method static PublicApiClient publicApi(?string $name = null)
 * @method static bool rememberAttempt(Endpoint $endpoint, Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?int $pointType = null, MeasurementType $measurementType = MeasurementType::Hourly, ?Nif $authorizedNif = null, ?DateTimeInterface $at = null, ?string $account = null)
 *
 * @mixin DatadisClient
 *
 * @see \Lenorix\LaravelDatadisClient\LaravelDatadisClient
 */
class LaravelDatadisClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Lenorix\LaravelDatadisClient\LaravelDatadisClient::class;
    }
}
