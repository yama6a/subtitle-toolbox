<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class ScaleCommand extends WriteCommand
{
    private float $factor = 1;


    public function name(): string
    {
        return "scale";
    }


    public function summary(): string
    {
        return "Multiplies all cue times by a factor.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --factor FACTOR [options]"];
    }


    protected function details(): string
    {
        return "For example, --factor 1.001 fixes a subtitle that drifts 3.6 s per hour. Without --output, --output-dir or\n" .
               "--in-place, the result of one input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [Option::value("factor", "FACTOR", "Factor greater than 0 for every time.")];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->factor = $arguments->positiveFloat("factor") ?? self::fail("Pass --factor FACTOR.");
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $subtitle->scale($this->factor);
    }
}
