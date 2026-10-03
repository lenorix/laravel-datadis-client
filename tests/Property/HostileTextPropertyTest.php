<?php

use Eris\Generator;
use Eris\Generators;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;
use Lenorix\LaravelDatadisClient\Tests\Support\FormatsOnceOutputStyle;

/*
 * Text from Datadis or from the user goes to the console: a distributor's name, an error description, an answer to an
 * authorization, an account name. Whatever it looks like (console tags, unbalanced brackets, Unicode), the command must
 * not crash, must keep its exit code and must show the text: raw on a line, with `<` swapped for `‹` in a table cell.
 */
function hostileText(): Generator
{
    $pieces = ['<', '>', '</>', '<fg=red>', '<fg=foo>', '<bg=blue;options=bold>', '<info>', '<error>', '<href=https://x>', '\\<', '\\', '&lt;', ' ', 'é', '日本', 'ñ', '%s', '{0}', "'", '"', 'abc', 'X'];

    return Generators::map(
        fn (array $parts) => implode('', $parts),
        Generators::bind(Generators::choose(1, 4), fn (int $count) => Generators::vector($count, Generators::elements(...$pieces))),
    );
}

it('never crashes on hostile text from Datadis or the user, and shows it', function () {
    app()->bind(OutputStyle::class, fn ($app, array $parameters) => new FormatsOnceOutputStyle($parameters['input'], $parameters['output']));

    $this->limitTo(min(iterations(), 100))->forAll(hostileText())->when(fn (string $text) => trim($text) !== '')->disableShrinking()->then(function (string $text) {
        FakesDatadis::fake([
            '*/get-supplies*' => Http::response(['supplies' => [[
                'cups' => FakesDatadis::CUPS, 'distributor' => $text, 'pointType' => 5, 'distributorCode' => '2', 'validDateFrom' => '2020/01/01', 'validDateTo' => '',
            ]], 'distributorError' => [['distributorCode' => '2', 'distributorName' => 'D', 'errorCode' => '500', 'errorDescription' => 'said: '.$text]]]),
            '*/new-authorization*' => Http::response('answer: '.$text, 200, ['Content-Type' => 'text/plain']),
        ]);
        app('cache')->store()->clear();

        // A table of rows and a warning beside them.
        $code = Artisan::call('datadis:supplies');
        $output = Artisan::output();
        expect($code)->toBe(0);
        expect(str_contains($output, str_replace('<', '‹', $text)))->toBeTrue('the table cell of '.json_encode($text).' in '.json_encode($output));
        expect(str_contains($output, 'said: '.$text))->toBeTrue('the warning of '.json_encode($text).' in '.json_encode($output));

        // The answer of a write.
        $code = Artisan::call('datadis:authorize', ['nif' => '12345678Z']);
        expect($code)->toBe(0);
        expect(Artisan::output())->toContain('answer: '.$text);

        // An account name the user typed: the command fails cleanly and says what it was given.
        $code = Artisan::call('datadis:supplies', ['--account' => $text]);
        expect($code)->toBe(1);
        expect(Artisan::output())->toContain($text);
    });
});
