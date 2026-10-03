<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Subtitle;

final class RetimeEdit extends Edit
{
    public function __construct(
        private readonly ?float $shift = null,
        private readonly ?float $shiftAfter = null,
        private readonly ?float $scale = null,
        private readonly ?float $fromFps = null,
        private readonly ?float $toFps = null,
    ) {
    }


    public static function options(): array
    {
        return [
            Option::value("shift", "SECONDS", "Seconds to add to every time, for example 2.5, or -2.5 to show the cues earlier."),
            Option::value("shift-after", "SECONDS", "Shift only the cues that start at this time or later."),
            Option::value("scale", "FACTOR", "Factor greater than 0 for every time."),
            Option::value("from-fps", "RATE", "Frame rate of the video that the subtitle fits now. Needs --to-fps."),
            Option::value("to-fps", "RATE", "Frame rate of the video that the subtitle must fit. Needs --from-fps."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        $edit = new self(
            $arguments->float("shift"),
            $arguments->float("shift-after"),
            $arguments->positiveFloat("scale"),
            $arguments->positiveFloat("from-fps"),
            $arguments->positiveFloat("to-fps"),
        );
        if ($edit->shiftAfter !== null && $edit->shift === null) {
            Command::fail("--shift-after needs --shift.");
        }
        if (($edit->fromFps === null) !== ($edit->toFps === null)) {
            Command::fail("Pass --from-fps and --to-fps together.");
        }

        return $edit->shift === null && $edit->scale === null && $edit->fromFps === null ? null : $edit;
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        if ($this->shift !== null) {
            $subtitle->shift($this->shift, $this->shiftAfter);
        }
        if ($this->scale !== null) {
            $subtitle->scale($this->scale);
        }
        if ($this->fromFps !== null && $this->toFps !== null) {
            $subtitle->convertFrameRate($this->fromFps, $this->toFps);
        }

        return $subtitle;
    }
}
