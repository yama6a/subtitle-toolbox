<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class FpsCommand extends RemovedCommand
{
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
        return "Removed. Use retime --from-fps RATE --to-fps RATE.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --from RATE --to RATE [options]"];
    }


    protected function details(): string
    {
        return "fps exits with code 2 and prints the matching retime call, with --from as --from-fps and --to as --to-fps.";
    }


    protected function replacement(array $arguments): string
    {
        return self::replacementCall("retime", $arguments, ["from" => "from-fps", "to" => "to-fps"]);
    }
}
