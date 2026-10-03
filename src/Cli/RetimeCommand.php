<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class RetimeCommand extends WriteCommand
{
    protected ?float $shift = null;

    protected ?float $shiftAfter = null;

    protected ?float $scale = null;

    protected ?float $fromFps = null;

    protected ?float $toFps = null;


    public function name(): string
    {
        return "retime";
    }


    public function summary(): string
    {
        return "Shifts and scales all cue times, or fits them to a video with another frame rate.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--shift SECONDS] [--scale FACTOR] [--from-fps RATE --to-fps RATE] [options]"];
    }


    protected function details(): string
    {
        return "Pass one or more edits. retime applies them in this order: --shift, --scale, --from-fps and --to-fps.\n" .
               "A time that becomes negative becomes 0. --scale 1.001 fixes a subtitle that drifts 3.6 s per hour.\n" .
               "--from-fps 25 --to-fps 23.976 fits a subtitle for a 25 fps release to a 23.976 fps video. Without\n" .
               "--output, --output-dir or --in-place, the result of one input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("shift", "SECONDS", "Seconds to add to every time, for example 2.5, or -2.5 to show the cues earlier."),
            Option::value("shift-after", "SECONDS", "Shift only the cues that start at this time or later."),
            Option::value("scale", "FACTOR", "Factor greater than 0 for every time."),
            Option::value("from-fps", "RATE", "Frame rate of the video that the subtitle fits now. Needs --to-fps."),
            Option::value("to-fps", "RATE", "Frame rate of the video that the subtitle must fit. Needs --from-fps."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->readEdits($arguments);
    }


    protected function readEdits(Arguments $arguments): void
    {
        $this->shift      = $arguments->float("shift");
        $this->shiftAfter = $arguments->float("shift-after");
        $this->scale      = $arguments->positiveFloat("scale");
        $this->fromFps    = $arguments->positiveFloat("from-fps");
        $this->toFps      = $arguments->positiveFloat("to-fps");

        if ($this->shiftAfter !== null && $this->shift === null) {
            self::fail("--shift-after needs --shift.");
        }
        if (($this->fromFps === null) !== ($this->toFps === null)) {
            self::fail("Pass --from-fps and --to-fps together.");
        }
        if ($this->shift === null && $this->scale === null && $this->fromFps === null) {
            self::fail("Pass --shift SECONDS, --scale FACTOR, or --from-fps RATE and --to-fps RATE.");
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
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
    }
}
