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
        ->toEqualCanonicalizing(['datadis-development', 'datadis-electricity-domain', 'datadis-sync', 'datadis-testing']);
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

it('has PHP examples that parse', function (string $path) {
    preg_match_all('/```php\n(.*?)```|<code-snippet[^>]*>\n(.*?)<\/code-snippet>/s', file_get_contents($path), $blocks);
    $examples = array_filter(array_merge($blocks[1], $blocks[2]));

    expect($examples)->not->toBeEmpty();

    foreach ($examples as $example) {
        // An example may be a method on its own: then it parses inside a class.
        $imports = implode("\n", preg_grep('/^use /', explode("\n", $example)));
        $method = implode("\n", preg_grep('/^use /', explode("\n", $example), PREG_GREP_INVERT));

        $parses = static function (string $code): bool {
            try {
                token_get_all($code, TOKEN_PARSE);

                return true;
            } catch (ParseError) {
                return false;
            }
        };

        expect($parses('<?php '.$example) || $parses("<?php {$imports}\nclass Example {{$method}}"))->toBeTrue("An example does not parse in {$path}:\n{$example}");
    }
})->with(array_merge($skills, [__DIR__.'/../resources/boost/guidelines/core.blade.php']));

it('has README examples that parse', function () {
    preg_match_all('/```php\n(.*?)```/s', file_get_contents(__DIR__.'/../README.md'), $blocks);

    expect($blocks[1])->not->toBeEmpty();

    foreach ($blocks[1] as $example) {
        $parses = static function (string $code): bool {
            try {
                token_get_all($code, TOKEN_PARSE);

                return true;
            } catch (ParseError) {
                return false;
            }
        };

        // An example may be a method, or the entries of a config array.
        $imports = implode("\n", preg_grep('/^use /', explode("\n", $example)));
        $body = implode("\n", preg_grep('/^use /', explode("\n", $example), PREG_GREP_INVERT));

        expect($parses('<?php '.$example) || $parses("<?php {$imports}\nclass Example {{$body}}") || $parses("<?php [{$body}];"))->toBeTrue("A README example does not parse:\n{$example}");
    }
});

it('cites official sources, and only links to the places it means to', function (string $path) {
    $contents = file_get_contents($path);

    expect($contents)->toMatch('/^#{2,3} (Sources|Official sources)$/m');

    preg_match_all('#https?://[^\s)>"\'`\]]+#', $contents, $urls);
    $allowed = ['www.boe.es', 'www.cnmc.es', 'datadis.es', 'laravel.com', 'github.com'];

    expect($urls[0])->not->toBeEmpty();

    foreach ($urls[0] as $url) {
        $host = parse_url($url, PHP_URL_HOST);

        expect(str_starts_with($url, 'https://'))->toBeTrue("Not https: {$url}");
        expect(in_array($host, $allowed, true))->toBeTrue("Unexpected host: {$url}");

        if ($host === 'github.com') {
            expect($url)->toStartWith('https://github.com/lenorix/datadis-php-client');
        }
    }
})->with(array_merge($skills, [__DIR__.'/../resources/boost/guidelines/core.blade.php']));

it('links the electricity domain skill to the Circular, the Real Decreto, the CNMC and Datadis', function () {
    $domain = file_get_contents(__DIR__.'/../resources/boost/skills/datadis-electricity-domain/SKILL.md');

    expect($domain)->toContain(
        'https://www.boe.es/buscar/act.php?id=BOE-A-2020-1066',   // Circular CNMC 3/2020
        'https://www.boe.es/buscar/act.php?id=BOE-A-2007-16478',  // Real Decreto 1110/2007
        'https://www.cnmc.es/sites/default/files/editor_contenidos/Energia/Consumidores/3.1.%20El%20CUPS.pdf',
        'https://datadis.es/private-api',
    );
    // The article behind each rule.
    expect($domain)->toContain('art. 6.2', 'art. 7.3', 'art. 9.5', 'art. 7');
});
