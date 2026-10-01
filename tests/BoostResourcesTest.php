<?php

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Yaml\Yaml;

$skills = glob(__DIR__.'/../resources/boost/skills/*/SKILL.md');

it('ships a Boost guideline that compiles', function () {
    $path = __DIR__.'/../resources/boost/guidelines/core.blade.php';

    expect($path)->toBeFile();
    $compiled = Blade::compileString(file_get_contents($path));

    expect($compiled)->toContain('Laravel Datadis Client');
    // Snippets sit in @verbatim: a directive interpreted by accident would leave PHP in the output.
    expect($compiled)->not->toContain('<?php')->not->toContain('<?=');
});

it('ships the Boost skills', function () use ($skills) {
    expect(array_map(fn ($p) => basename(dirname($p)), $skills))
        ->toEqualCanonicalizing(['datadis-development', 'datadis-sync', 'datadis-testing']);
});

it('gives every skill valid frontmatter named after its folder', function (string $path) {
    $contents = file_get_contents($path);

    expect(preg_match('/\A---\r?\nname: (?<name>[a-z0-9-]+)\r?\ndescription: (?<description>.+?)\r?\n---\r?\n/', $contents, $m))->toBe(1);
    expect($m['name'])->toBe(basename(dirname($path)));
    expect(strlen($m['description']))->toBeLessThanOrEqual(1024);
})->with($skills);

it('keeps Blade markup out of the skills, which Boost copies as they are', function (string $path) {
    expect(file_get_contents($path))->not->toContain('@verbatim')->not->toContain('<code-snippet');
})->with($skills);

it('has frontmatter that Boost can parse as YAML', function (string $path) {
    preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', file_get_contents($path), $m);

    $frontmatter = Yaml::parse($m[1]);

    expect($frontmatter)->toHaveKeys(['name', 'description']);
    expect($frontmatter['description'])->toBeString();
})->with($skills);
