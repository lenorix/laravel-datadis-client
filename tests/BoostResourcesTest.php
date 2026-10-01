<?php

use Illuminate\Support\Facades\Blade;

$skills = glob(__DIR__.'/../resources/boost/skills/*/SKILL.md');

it('ships a Boost guideline that compiles', function () {
    $path = __DIR__.'/../resources/boost/guidelines/core.blade.php';

    expect($path)->toBeFile();
    expect(Blade::compileString(file_get_contents($path)))->toContain('Laravel Datadis Client');
});

it('ships the Boost skills', function () use ($skills) {
    expect(array_map(fn ($p) => basename(dirname($p)), $skills))
        ->toEqualCanonicalizing(['datadis-development', 'datadis-sync', 'datadis-testing']);
});

it('gives every skill valid frontmatter named after its folder', function (string $path) {
    $contents = file_get_contents($path);

    expect(preg_match('/\A---\nname: (?<name>[a-z0-9-]+)\ndescription: (?<description>.+)\n---\n/', $contents, $m))->toBe(1);
    expect($m['name'])->toBe(basename(dirname($path)));
    expect(strlen($m['description']))->toBeLessThanOrEqual(1024);
})->with($skills);
