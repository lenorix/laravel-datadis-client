# Changelog

All notable changes to `laravel-datadis-client` will be documented in this file.

## Unreleased

- Ship Laravel Boost guidelines and skills (`datadis-development`, `datadis-sync`, `datadis-testing`).
- Require `lenorix/datadis-client` ^0.3.0: a `401` on a guarded query is never resent and a range before the contract start is refused locally.
- Report `RepetitionWindowException` as a warning through the exception handler.
- Test the 24 hour guard under real concurrency (several processes on a file cache) and keep the suite off the network.
- Require 100 % coverage of `src/` in CI (`composer test-coverage`); add `http.options` for extra Guzzle options.
- Fix the HTTP documentation (plain Guzzle by default, Laravel's `Http` in tests), add tests for transport errors, expired tokens, an unusable cache store and the command's named accounts and invalid holders, run the property tests 200 times (`composer test-pbt`: 2000) and audit the dependencies in CI.
- Default `http.stack` to plain Guzzle (Laravel's stack only in the test environment), fail on a `cache.store` that is not a store name, and document the operations that change data.
- Add `http.stack` (`DATADIS_HTTP_STACK`): `guzzle` keeps Laravel's events and recorders from seeing the login password and token.
- Document the ledger key.
- Expose `PublicApiClient` (container binding and `publicApi()`), sharing the login with the private client.
- Read the credentials of the account named `default` from `services.datadis`, which wins however a setting is spelled (`api_version` or `api-version`) and ignores blank values.
- Integrate `lenorix/datadis-client` with Laravel: container binding, facade, named accounts, Laravel `Http` handler, shared token cache and atomic 24 hour guard on a cache store, `datadis:supplies` command.
