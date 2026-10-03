<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class ShiftCommand extends RetimeCommand
{
    public function name(): string
    {
        return "shift";
    }


    public function listed(): bool
    {
        return false;
    }


    public function summary(): string
    {
        return "Deprecated. Use retime --shift.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --by SECONDS [--after SECONDS] [options]"];
    }


    protected function details(): string
    {
        return "shift runs retime with --by as --shift and --after as --shift-after, and prints a deprecation warning\n" .
               "on standard error. It takes the other options of retime.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("by", "SECONDS", "Seconds to add to every time, for example 2.5, or -2.5 to show the cues earlier."),
            Option::value("after", "SECONDS", "Move only the cues that start at this time or later."),
        ];
    }


    public function run(array $arguments, Console $console): int
    {
        $console->err("shift is deprecated. Use: " . self::replacementCall("retime", $arguments, ["by" => "shift", "after" => "shift-after"]) . "\n");

        return parent::run($arguments, $console);
    }


    protected function readEdits(Arguments $arguments): void
    {
        $this->shift      = $arguments->float("by") ?? self::fail("Pass --by SECONDS.");
        $this->shiftAfter = $arguments->float("after");
    }
}
