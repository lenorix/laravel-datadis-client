# Contributing

Thanks for helping. Open an issue or a pull request on [GitHub](https://github.com/lenorix/laravel-datadis-client).

## Set up

```bash
composer install
```

The package needs PHP 8.4 and Laravel 13. No test calls the real Datadis, and none may: a consumption query it receives cannot be repeated for 24 hours.

## Before you push

```bash
composer test            # Pest, including the Eris property tests (200 cases each)
composer test-pbt        # the property tests, 2000 cases each
composer test-coverage   # fails under 100 % of src/ (needs Xdebug or PCOV)
composer phpstan         # PHPStan, level max
composer format          # Pint fixes the style; composer lint only checks it
composer audit           # known vulnerabilities in the dependencies
```

- Add a test for every change; the coverage of `src/` must stay at 100 %.
- A test that needs Datadis fakes it with `Http::fake()` (the test environment uses Laravel's HTTP stack) or or, with `datadis-client.http.stack` set to `guzzle`, with a Guzzle `MockHandler` in `datadis-client.http.options.handler` (on the `laravel` stack that option is ignored).
- A failing property prints a seed: reproduce it with `ERIS_SEED=<seed> vendor/bin/pest --filter '<test name>'`.
- Keep credentials, tokens, NIFs and CUPS out of code, tests and commits: use the placeholders already there (`00000000T`, `12345678Z`, `ES0000000000000000AA0A`).
- Update the README, the Laravel Boost guideline and skills (`resources/boost`) and the CHANGELOG when behaviour changes.
- Every fact of the Spanish electricity domain in `resources/boost` must cite an official source (the BOE, the CNMC, the Datadis manual) with the article or section, and be pinned to the package's calendars in `tests/DomainFactsTest.php`. Check the links when you change them.

## Security

Report vulnerabilities privately: see [SECURITY.md](SECURITY.md).
