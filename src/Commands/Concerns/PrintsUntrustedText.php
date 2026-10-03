<?php

declare(strict_types=1);

namespace Lenorix\LaravelDatadisClient\Commands\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints text that is not ours: what comes from Datadis or from the user (an account name, a distributor's name, an
 * answer) without letting it be read as console formatting.
 *
 * The console formatter would read `<fg=foo>` in it as a style and fail, and a wrapper of the output that formats twice
 * (the one Laravel's skeleton installs for AI agents) undoes any escape. A backslash is a hazard too: before the closing tag of
 * the style it escapes it, and the text loses its last character. So text with a `<` or a `\` is written raw, unformatted,
 * and the cells of a table, which cannot be, have their `<` swapped for a look-alike that cannot open a tag, and a backslash
 * before a `>` (which the formatter would take for an escape and drop) keeps its backslash and gets a look-alike of the `>`.
 *
 * For a class that extends Illuminate\Console\Command.
 */
trait PrintsUntrustedText
{
    public function line($string, $style = null, $verbosity = null)
    {
        $string = (string) $string;

        if (str_contains($string, '<') || str_contains($string, '\\')) {
            /** @var int<0, 511> $type */
            $type = $this->parseVerbosity($verbosity) | OutputInterface::OUTPUT_RAW;

            $this->output->writeln($string, $type);

            return;
        }

        parent::line($string, $style, $verbosity);
    }

    // Not parent::warn() and parent::error(): they render through components that read the text as markup.
    public function warn($string, $verbosity = null)
    {
        $this->line($string, 'comment', $verbosity);
    }

    public function error($string, $verbosity = null)
    {
        $this->line($string, 'error', $verbosity);
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>  $rows
     * @param  array<int, string|TableStyle>  $columnStyles
     */
    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $neutral = static fn (mixed $cell): mixed => is_string($cell) ? str_replace(['<', '\\>'], ['‹', '\\›'], $cell) : $cell;

        parent::table($headers, array_map(static fn (mixed $row): mixed => is_array($row) ? array_map($neutral, $row) : $row, $rows instanceof Arrayable ? $rows->toArray() : $rows), $tableStyle, $columnStyles);
    }
}
