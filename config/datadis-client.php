<?php

// config for Lenorix/LaravelDatadisClient
return [

    /*
    |--------------------------------------------------------------------------
    | Default account
    |--------------------------------------------------------------------------
    |
    | The key of `accounts` that the container binding, the facade and the
    | artisan commands use when no account is named.
    |
    */
    'default' => env('DATADIS_ACCOUNT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | The credentials of the account named `default` are read from `services.datadis`
    | in config/services.php, Laravel's place for third-party services, and win
    | over the keys below:
    |
    |     'datadis' => [
    |         'username' => env('DATADIS_USERNAME'),
    |         'password' => env('DATADIS_PASSWORD'),
    |     ],
    |
    | Each account is a Datadis login (the NIF, NIE or CIF you registered with
    | and its password). Besides `username` and `password` it accepts the
    | settings of Lenorix\DatadisClient\DatadisClient::fromArray(): `api_version`
    | (v1 or v2), `timezone`, `timeout`, `connect_timeout`, `base_url`,
    | `user_agent` and `check_username_control`.
    |
    */
    'accounts' => [
        'default' => [
            'username' => env('DATADIS_USERNAME'),
            'password' => env('DATADIS_PASSWORD'),
            'api_version' => env('DATADIS_API_VERSION', 'v2'),
            'timezone' => env('DATADIS_TIMEZONE', 'Europe/Madrid'),
            // Datadis is slow and a request that times out may still count: do not go much lower.
            'timeout' => env('DATADIS_TIMEOUT', 120),
            'connect_timeout' => env('DATADIS_CONNECT_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | The login token and the 24 hour guard live in this cache store, shared by
    | every worker, so the guard needs a store whose add() is atomic: redis,
    | memcached, database and dynamodb work from any host; `file` locks the
    | file, so it only coordinates processes on the same host and filesystem
    | (not several servers); `array` lives in one process, so it protects
    | nothing across workers or runs. Null uses the default store. Protect it
    | like a password: it holds the Datadis token.
    |
    */
    'cache' => [
        'store' => env('DATADIS_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 24 hour guard
    |--------------------------------------------------------------------------
    |
    | Datadis refuses an identical consumption, maximum power or reactive query
    | for 24 hours and counts the refused ones. Only a keyed hash of each query
    | is stored. `key` is that secret (at least 16 bytes); null derives it from
    | `app.key`. Changing it forgets every query already made.
    |
    */
    'ledger' => [
        'key' => env('DATADIS_LEDGER_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log level of a refused repeat
    |--------------------------------------------------------------------------
    |
    | A query Datadis would refuse for 24 hours is refused here before it is
    | sent: expected under a scheduler, so it is reported as a warning. The
    | package sets this on the exception handler after your own
    | `withExceptions()`, so it wins over a level you set there for
    | RepetitionWindowException; set null to leave your handler alone. It must be
    | a PSR-3 level (emergency, alert, critical, error, warning, notice, info,
    | debug): anything else fails when the client is built.
    |
    */
    'report_level' => env('DATADIS_REPORT_LEVEL', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | HTTP stack
    |--------------------------------------------------------------------------
    |
    | `guzzle` sends the calls through plain Guzzle with the package's
    | settings: nothing of Laravel sees them. `laravel` sends them through
    | Laravel's Http handler stack, so Http::fake() and Http::assertSent() work,
    | but Laravel's request events, global middleware and recorders (Telescope,
    | Nightwatch...) also see the login request, with the password, and its
    | answer, with the token.
    |
    | Unset, it is `guzzle`, except in the test environment (APP_ENV=testing),
    | where it is `laravel`. Set DATADIS_HTTP_STACK=laravel to fake Datadis in
    | another environment, knowing what the recorders there will see.
    |
    */
    'http' => [
        'stack' => env('DATADIS_HTTP_STACK'),

        // Extra Guzzle options for every call (a proxy, `verify`, a custom `handler`...), on top of the
        // package's own timeouts and settings. With the `laravel` stack its handler is kept.
        'options' => [],

        // Network failures and 502, 503 and 504 answers are retried with exponential backoff, only for the
        // calls where repeating is harmless (the login, the lists and the reads). Data queries (consumption,
        // maximum power, reactive) and the calls that change data are never retried, because a request that
        // may have reached Datadis uses up the 24 hour rule or changes data. `max` 0 turns it off.
        'retries' => [
            'max' => env('DATADIS_HTTP_RETRIES', 2),
            'base_delay_ms' => 1000,
            'max_delay_ms' => 30000,
        ],
    ],

];
