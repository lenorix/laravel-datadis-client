<?php

namespace Lenorix\LaravelDatadisClient\Facades;

use DateTimeInterface;
use Illuminate\Support\Facades\Facade;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/**
 * @method static DatadisClient account(?string $name = null)
 * @method static PublicApiClient publicApi(?string $name = null)
 * @method static bool rememberConsumption(Cups $cups, string $distributorCode, int $pointType, Month $startDate, ?Month $endDate = null, MeasurementType $measurementType = MeasurementType::Hourly, ?Nif $authorizedNif = null, ?DateTimeInterface $at = null, ?string $account = null)
 * @method static bool rememberMaxPower(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?DateTimeInterface $at = null, ?string $account = null)
 * @method static bool rememberReactive(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?DateTimeInterface $at = null, ?string $account = null)
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
