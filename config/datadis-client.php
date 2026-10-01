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
    | every worker. Use one that adds a key atomically (redis, memcached,
    | database, dynamodb): with `file` or `array` two workers may still send the
    | same query. Null uses the default store. Protect it like a password: it
    | holds the Datadis token.
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

];
