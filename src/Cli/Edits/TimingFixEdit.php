<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class TimingFixEdit extends Edit
{
    private function __construct(
        private readonly bool $overlaps,
        private readonly ?float $minDuration,
        private readonly ?float $minGap,
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
            Option::flag("timing-fix-overlaps", "End each cue at least --timing-min-gap seconds before the next cue starts."),
            Option::value("timing-min-duration", "SECONDS", "Show each cue for at least this time where the next cue allows it."),
            Option::value("timing-min-gap", "SECONDS", "Gap between cues for --timing-fix-overlaps and --timing-min-duration. Default: 0."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needsOneOf($arguments, ["timing-fix-overlaps", "timing-min-duration"], "timing-min-gap");
        $minDuration = $arguments->positiveFloat("timing-min-duration");
        $minGap      = $arguments->nonNegativeFloat("timing-min-gap");
        if (!$arguments->has("timing-fix-overlaps") && $minDuration === null) {
            return null;
        }

        return new self($arguments->has("timing-fix-overlaps"), $minDuration, $minGap);
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->overlaps) {
            $subtitle->fixOverlaps(...Command::given(["minGap" => $this->minGap]));
        }
        if ($this->minDuration !== null) {
            $subtitle->extendShortCues($this->minDuration, ...Command::given(["minGap" => $this->minGap]));
        }

        return $subtitle;
    }
}
