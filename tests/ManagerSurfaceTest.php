<?php

use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;

/*
 * The public surface of the manager is the package's API (the facade forwards to it): what the application can call does not
 * change when its insides are moved.
 */
it('keeps the public surface of the manager', function () {
    expect(get_class_methods(Manager::class))->toEqualCanonicalizing([
        '__construct', 'account', 'publicApi', 'rememberConsumption', 'rememberMaxPower', 'rememberReactive', 'reportLevel', '__call',
    ]);
});

it('keeps the constants of the manager that applications and the provider read', function () {
    expect((new ReflectionClass(Manager::class))->getConstants())->toBe([
        'REPORT_LEVELS' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
        'SERVICES_ACCOUNT' => 'default',
    ]);
});
