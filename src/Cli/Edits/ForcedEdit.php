<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

final class ForcedEdit extends Edit
{
    public static function group(): string
    {
        return "forced";
    }


    public static function summary(): string
    {
        return "Keep only the forced cues.";
    }


    public static function options(): array
    {
        return [Option::flag("forced-only", "Keep only the forced cues, for example the translations of signs.")];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        return $arguments->has("forced-only") ? new self() : null;
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        return $subtitle->onlyForced();
    }
}
