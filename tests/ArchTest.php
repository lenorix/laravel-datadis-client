<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the package code is strict about its dependencies')
    ->expect('Lenorix\LaravelDatadisClient')
    ->not->toUse(['die', 'exit', 'env']);

arch('every source file declares strict types')
    ->expect('Lenorix\LaravelDatadisClient')
    ->toUseStrictTypes();

arch('the internal classes are final, and the commands, the events and the testing helper do not reach for them')
    ->expect('Lenorix\LaravelDatadisClient\Internal')
    ->toBeFinal()
    ->not->toBeUsedIn(['Lenorix\LaravelDatadisClient\Commands', 'Lenorix\LaravelDatadisClient\Events', 'Lenorix\LaravelDatadisClient\Testing', 'Lenorix\LaravelDatadisClient\Facades']);

arch('only the manager, and the classes it hands work to, use the internal ones')
    ->expect('Lenorix\LaravelDatadisClient\Internal')
    ->toOnlyBeUsedIn(['Lenorix\LaravelDatadisClient\LaravelDatadisClient', 'Lenorix\LaravelDatadisClient\Internal']);
