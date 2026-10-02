# Changelog

All notable changes to `laravel-datadis-client` will be documented in this file.

## Unreleased

First release. Laravel integration of [`lenorix/datadis-client`](https://github.com/lenorix/datadis-php-client) ^0.3.0, for PHP 8.4 and Laravel 13.

### Added

- **Client**: the whole client, read and write (supplies, contracts, consumption, power, reactive energy, authorizations, groups, partner accounts and the open data), with a test for every method; `DatadisClient` and `PublicApiClient` in the container, the `LaravelDatadisClient` facade (the default account, plus `account()` and `publicApi()`), named accounts and holders (`forHolder()`), and artisan commands for supplies, contracts, consumption and authorizations (`datadis:supplies`, `datadis:contract`, `datadis:consumption`, `datadis:authorizations`, `datadis:authorize`, `datadis:authorization:cancel`). The commands check every input, and the month range of `datadis:consumption`, before the first request. Their exit code is 1 when a distributor failed and no data came back.
- **Configuration**: the credentials of the account named `default` come from `services.datadis` and win over `config/datadis-client.php` however a setting is spelled (`api_version` or `api-version`) and ignore blank values. Settings for other accounts, the cache store, the ledger key, the HTTP stack and the log level live in `config/datadis-client.php`.
- **24 hour guard**: the login token and the record of guarded queries live in a Laravel cache store shared by all workers, and recording a query is atomic (`Cache::add()`), so only one of several simultaneous workers sends it. A `cache.store` that is not a store name fails instead of using the default store. The guard's secret is `ledger.key` as given, or an HMAC derived from `APP_KEY` (never the raw key), and a `ledger.key` that is not a text fails instead of being ignored. Repeats of the same query are refused for 24 hours and 10 minutes, so schedule them every second day.
- **Token and guard cache without events**: the cache store is used without Laravel's cache events, which would carry the token and the keys to Telescope's cache watcher.
- **HTTP stack** (`http.stack`, `http.options`): plain Guzzle by default, so Laravel's events and recorders never see the login password and token; `laravel` in the test environment, where `Http::fake()` works. The package refuses plain Guzzle in tests without a mock handler.
- **Retries** (`http.retries`, `DATADIS_HTTP_RETRIES`): network failures and 502, 503 and 504 answers are retried with backoff for the login, the lists and the reads, never for data queries nor for the calls that change data.
- **Logging**: a refused repeated query (`RepetitionWindowException`) is reported as a warning (`report_level`, a PSR-3 level or `null`).
- **Laravel Boost**: a guideline and the `datadis-development`, `datadis-electricity-domain`, `datadis-sync` and `datadis-testing` skills.
- **Documentation**: the Boost guideline and skills cite their official sources (Circular CNMC 3/2020 and RD 1110/2007 in the BOE, the CNMC guide to the CUPS, the Datadis manual and the client's documentation), with the article of each rule;  a README that goes straight to installing and using the package, `SECURITY.md` and `CONTRIBUTING.md`; every PHP example of the README and of the Boost resources is parse-tested.
- **Tests and CI**: Pest and Eris property tests (200 cases each by default, `composer test-pbt` for 2000), a multi-process concurrency test, properties for configuration combinations and command inputs, 100 % coverage of `src/` enforced in CI, PHPStan at level max, Pint, a dependency audit, a gitleaks scan of the history, and no test reaches the network.
