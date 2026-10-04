<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

final class TimingFixEdit extends Edit
{
    private function __construct(
        private readonly bool $overlaps,
        private readonly ?float $minDuration,
        private readonly float $minGap,
    ) {
    }


    public static function group(): string
    {
        return "timing";
    }


    public static function summary(): string
    {
        return "Fix overlaps and short cues.";
    }


    public static function options(): array
    {
        return [
            Option::flag("fix-overlaps", "End each cue at least --fix-min-gap seconds before the next cue starts."),
            Option::value("fix-min-duration", "SECONDS", "Show each cue for at least this time where the next cue allows it."),
            Option::value("fix-min-gap", "SECONDS", "Gap between cues for --fix-overlaps and --fix-min-duration. Default: 0."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needsOneOf($arguments, ["fix-overlaps", "fix-min-duration"], "fix-min-gap");
        $minDuration = $arguments->positiveFloat("fix-min-duration");
        $minGap      = $arguments->float("fix-min-gap") ?? 0.0;
        if ($minGap < 0) {
            Command::fail("The option --fix-min-gap must not be negative.");
        }
        if (!$arguments->has("fix-overlaps") && $minDuration === null) {
            return null;
        }

        return new self($arguments->has("fix-overlaps"), $minDuration, $minGap);
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->overlaps) {
            $subtitle->fixOverlaps($this->minGap);
        }
        if ($this->minDuration !== null) {
            $subtitle->extendShortCues($this->minDuration, $this->minGap);
        }

        return $subtitle;
    }
}
