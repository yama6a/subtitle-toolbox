<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class FpsCommand extends WriteCommand
{
    private float $fromFps = 25;

    private float $toFps = 25;


    public function name(): string
    {
        return "fps";
    }


    public function aliases(): array
    {
        return ["sync-fps"];
    }


    public function summary(): string
    {
        return "Retimes subtitles for a video with another frame rate.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --from RATE --to RATE [options]"];
    }


    protected function details(): string
    {
        return "For example, --from 25 --to 23.976 fits a subtitle for a 25 fps release to a 23.976 fps video.\n" .
               "The output keeps the input format, or takes the format of the --output extension. Without --output,\n" .
               "--output-dir or --in-place, the result of one input file goes to standard output.";
    }


    protected function hasFormatOptions(): bool
    {
        return false;
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("from", "RATE", "Frame rate of the video that the subtitle fits now."),
            Option::value("to", "RATE", "Frame rate of the video that the subtitle must fit."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->fromFps = $arguments->positiveFloat("from") ?? self::fail("Pass --from RATE.");
        $this->toFps   = $arguments->positiveFloat("to") ?? self::fail("Pass --to RATE.");
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $subtitle->convertFrameRate($this->fromFps, $this->toFps);
    }
}
