<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class ShiftCommand extends WriteCommand
{
    private float $seconds = 0;

    private ?float $after = null;


    public function name(): string
    {
        return "shift";
    }


    public function summary(): string
    {
        return "Moves all cues earlier or later by a number of seconds.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --by SECONDS [options]"];
    }


    protected function details(): string
    {
        return "A time that becomes negative becomes 0. Without --output, --output-dir or --in-place, the result of one\n" .
               "input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("by", "SECONDS", "Seconds to add to every time, for example 2.5, or -2.5 to show the cues earlier."),
            Option::value("after", "SECONDS", "Move only the cues that start at this time or later."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->seconds = $arguments->float("by") ?? self::fail("Pass --by SECONDS.");
        $this->after   = $arguments->float("after");
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $subtitle->shift($this->seconds, $this->after);
    }
}
