<?php

namespace Lenorix\LaravelDatadisClient\Tests\Support;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Laravel's skeleton installs an output wrapper (laravel/pao) that formats every line once before the console does it again.
 * A plain Testbench output has no such wrapper, so an escape that passes there can still crash in a real application: this
 * output formats once, as the wrapper does, and Laravel builds every command's output from it.
 */
class FormatsOnceOutputStyle extends OutputStyle
{
    public function writeln(string|iterable $messages, int $type = self::OUTPUT_NORMAL): void
    {
        $formatter = new OutputFormatter(false);
        $format = ($type & self::OUTPUT_RAW) === 0;

        parent::writeln(
            is_string($messages) ? ($format ? (string) $formatter->format($messages) : $messages) : array_map(fn (string $m) => $format ? (string) $formatter->format($m) : $m, [...$messages]),
            $type,
        );
    }
}
