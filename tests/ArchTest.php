<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the package code is strict about its dependencies')
    ->expect('Lenorix\LaravelDatadisClient')
    ->not->toUse(['die', 'exit', 'env']);
