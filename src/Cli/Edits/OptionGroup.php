<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Option;

/**
 * One option group of convert: an edit or the ASS writer settings.
 *
 * @internal
 */
interface OptionGroup
{
    /**
     * Returns the name of the option group in the help of convert, for "convert --help GROUP".
     */
    public static function group(): string;


    /**
     * Returns the one-line description of the option group in the help of convert.
     */
    public static function summary(): string;


    /**
     * @return list<Option>
     */
    public static function options(): array;


    /**
     * Tells if the options in $arguments need the word timestamps of the input.
     */
    public static function needsWordTimestamps(Arguments $arguments): bool;
}
