<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class ScaleCommand extends RetimeCommand
{
    public function name(): string
    {
        return "scale";
    }


    public function listed(): bool
    {
        return false;
    }


    public function summary(): string
    {
        return "Deprecated. Use retime --scale.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --factor FACTOR [options]"];
    }


    protected function details(): string
    {
        return "scale runs retime with --factor as --scale, and prints a deprecation warning on standard error.\n" .
               "It takes the other options of retime.";
    }


    protected function commandOptions(): array
    {
        return [Option::value("factor", "FACTOR", "Factor greater than 0 for every time.")];
    }


    public function run(array $arguments, Console $console): int
    {
        $console->err("scale is deprecated. Use: " . self::replacementCall("retime", $arguments, ["factor" => "scale"]) . "\n");

        return parent::run($arguments, $console);
    }


    protected function readEdits(Arguments $arguments): void
    {
        $this->scale = $arguments->positiveFloat("factor") ?? self::fail("Pass --factor FACTOR.");
    }
}
