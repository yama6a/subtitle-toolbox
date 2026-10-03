<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

/**
 * One group of convert options that share a prefix, with the checks of these options.
 */
abstract class Edit
{
    /**
     * @return list<Option>
     */
    abstract public static function options(): array;


    /**
     * Returns the edit, or null when $arguments hold no option that turns it on. Fails on invalid values.
     */
    abstract public static function fromArguments(Arguments $arguments): ?static;


    /**
     * Changes $subtitle and returns it, or returns a new subtitle. $label names the input in messages.
     */
    abstract public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle;


    /**
     * Fails when $arguments hold one of $options without the option $needed.
     *
     * @param list<string> $options
     */
    protected static function needs(Arguments $arguments, string $needed, array $options): void
    {
        foreach ($options as $option) {
            if ($arguments->has($option) && !$arguments->has($needed)) {
                Command::fail("Pass --$needed with --$option.");
            }
        }
    }
}
