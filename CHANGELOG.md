# Changelog

All notable changes to `laravel-datadis-client` will be documented in this file.

## Unreleased

First release. Laravel integration of [`lenorix/datadis-client`](https://github.com/lenorix/datadis-php-client) ^0.3.0, for PHP 8.4 and Laravel 13.

### Added

- **Client**: `DatadisClient` and `PublicApiClient` in the container, the `LaravelDatadisClient` facade (the default account, plus `account()` and `publicApi()`), named accounts and holders (`forHolder()`), and `php artisan datadis:supplies`.
- **Configuration**: the credentials of the account named `default` come from `services.datadis` and win over `config/datadis-client.php` however a setting is spelled (`api_version` or `api-version`) and ignore blank values. Settings for other accounts, the cache store, the ledger key, the HTTP stack and the log level live in `config/datadis-client.php`.
- **24 hour guard**: the login token and the record of guarded queries live in a Laravel cache store shared by all workers, and recording a query is atomic (`Cache::add()`), so only one of several simultaneous workers sends it. A `cache.store` that is not a store name fails instead of using the default store.
- **HTTP stack** (`http.stack`, `http.options`): plain Guzzle by default, so Laravel's events and recorders never see the login password and token; `laravel` in the test environment, where `Http::fake()` works. The package refuses plain Guzzle in tests without a mock handler.
- **Logging**: a refused repeated query (`RepetitionWindowException`) is reported as a warning (`report_level`, a PSR-3 level or `null`).
- **Laravel Boost**: a guideline and the `datadis-development`, `datadis-sync` and `datadis-testing` skills.
- **Tests and CI**: Pest and Eris property tests (200 cases each by default, `composer test-pbt` for 2000), a multi-process concurrency test, 100 % coverage of `src/` enforced in CI, PHPStan at level max, Pint, a dependency audit, and no test reaches the network.
