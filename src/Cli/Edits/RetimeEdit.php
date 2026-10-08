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
final class RetimeEdit extends Edit
{
    private function __construct(
        private readonly ?float $shift,
        private readonly ?float $shiftAfter,
        private readonly ?float $scale,
        private readonly ?float $fromFps,
        private readonly ?float $toFps,
    ) {
    }


    public static function group(): string
    {
        return "retime";
    }


    public static function summary(): string
    {
        return "Shift and scale the times, or change the frame rate.";
    }


    public static function options(): array
    {
        return [
            Option::value("shift", "SECONDS", "Seconds to add to every time, for example 2.5, or -2.5 to show the cues earlier."),
            Option::value("shift-after", "SECONDS", "Shift only the cues that start at this time or later."),
            Option::value("scale", "FACTOR", "Multiply every time by this factor. Must be greater than 0."),
            Option::value("from-fps", "RATE", "Frame rate of the video that the subtitle fits now. Needs --to-fps."),
            Option::value("to-fps", "RATE", "Frame rate of the video that the subtitle must fit. Needs --from-fps."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "shift", ["shift-after"]);
        $edit = new self(
            $arguments->float("shift"),
            $arguments->float("shift-after"),
            $arguments->positiveFloat("scale"),
            $arguments->positiveFloat("from-fps"),
            $arguments->positiveFloat("to-fps"),
        );
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
