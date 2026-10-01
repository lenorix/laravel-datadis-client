# Changelog

All notable changes to `laravel-datadis-client` will be documented in this file.

## Unreleased

- Ship Laravel Boost guidelines and skills (`datadis-development`, `datadis-sync`, `datadis-testing`).
- Expose `PublicApiClient` (container binding and `publicApi()`), sharing the login with the private client.
- Read the default account credentials from `services.datadis`.
- Integrate `lenorix/datadis-client` with Laravel: container binding, facade, named accounts, Laravel `Http` handler, shared token cache and atomic 24 hour guard on a cache store, `datadis:supplies` command.
