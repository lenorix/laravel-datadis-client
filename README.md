# Datadis client for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/laravel-datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-datadis-client)

Laravel integration of [`lenorix/datadis-client`](https://github.com/lenorix/datadis-php-client), the client for [Datadis](https://datadis.es) (supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy). It wires the client to what your application already has:

- **HTTP**: every call goes through Laravel's `Http` handler stack, so `Http::fake()` and `Http::assertSent()` work.
- **Cache**: the login token and the **24 hour guard** (Datadis refuses an identical query for 24 hours and counts the refused ones) live in a Laravel cache store shared by all workers. Recording a query is atomic (`Cache::add()`), so two workers never both send it.
- **Configuration**: one or more accounts in `config/datadis-client.php`, container binding, facade and an artisan command.

## Installation

```bash
composer require lenorix/laravel-datadis-client
php artisan vendor:publish --tag="laravel-datadis-client-config"
```

Requires PHP 8.4 and Laravel 11, 12 or 13. Set your Datadis login in `.env`:

```dotenv
DATADIS_USERNAME=A00000000
DATADIS_PASSWORD=your-password
```

## Usage

Inject the client, or use the facade, which forwards to the default account:

```php
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\LaravelDatadisClient\Facades\LaravelDatadisClient as Datadis;

public function __invoke(DatadisClient $client)
{
    $supply = $client->findSupply(Cups::fromString('ES0000000000000000AA0A'));
    $result = $client->getConsumptionDataOf($supply, Month::of(2026, 7));
}

$supplies = Datadis::getSupplies();
```

See the [client documentation](https://github.com/lenorix/datadis-php-client#readme) for every call, the results and the errors.

### Several accounts and holders

```php
Datadis::account('other')->getSupplies();

$holder = Datadis::forHolder(Nif::fromString('00000000T')); // someone who authorized your account
```

Define each account under `accounts` in the configuration with the same keys as `default`.

### Command

```bash
php artisan datadis:supplies [--account=other] [--holder=00000000T]
```

### Queued jobs

The client holds a password and cannot be serialised: resolve it in `handle()`, never keep it in a property.

## Configuration

| Key | Purpose |
|---|---|
| `default` | Account used by the binding, the facade and the command (`DATADIS_ACCOUNT`). |
| `accounts.*` | `username`, `password`, `api_version` (`v1`/`v2`), `timezone`, `timeout`, `connect_timeout`, `base_url`, `user_agent`. |
| `cache.store` | Store for token and guard (`DATADIS_CACHE_STORE`); default store if empty. Use Redis, Memcached, database or DynamoDB: with `file` or `array` the guard cannot be atomic across processes. It holds the token, so protect it like a password. |
| `ledger.key` | Secret of the guard's keyed hash, at least 16 bytes (`DATADIS_LEDGER_KEY`); derived from `APP_KEY` if empty. Changing it forgets the queries already made. |

A repeated query fails with `RepetitionWindowException` before anything is sent.

## Testing

```php
Http::fake([
    '*/nikola-auth/tokens/login' => Http::response($token),
    '*/api-private/api/get-supplies*' => Http::response(['supplies' => [...], 'distributorError' => []]),
]);
```

```bash
composer test
```

## Credits

- [Jesus Hernandez](https://github.com/lenorix)

## License

The MIT License (MIT). See [License File](LICENSE.md).
