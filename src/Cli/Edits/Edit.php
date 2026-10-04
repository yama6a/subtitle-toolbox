<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

/**
 * One option group of convert, with the checks of its options. Where a group has a prefix, it is the group name, such
 * as --structure- or --sdh-. The retime, snap, text and masking groups also hold options without it: --shift and
 * --video-fps keep the names of the retime and validate commands, and --case and --mute-edl read better alone.
 *
 * @internal
 */
abstract class Edit
{
    /**
     * Returns the name of the option group in the help of convert, for "convert --help GROUP".
     */
    abstract public static function group(): string;


    /**
     * Returns the one-line description of the option group in the help of convert.
     */
    abstract public static function summary(): string;


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


    /**
     * Fails when $arguments hold $option without one of the options $needed.
     *
     * @param non-empty-list<string> $needed
     */
    protected static function needsOneOf(Arguments $arguments, array $needed, string $option): void
    {
        if (!$arguments->has($option) || array_filter($needed, $arguments->has(...)) !== []) {
            return;
        }
        $last = "--" . array_pop($needed);

        Command::fail("Pass " . ($needed === [] ? $last : "--" . implode(", --", $needed) . " or $last") . " with --$option.");
    }
}
