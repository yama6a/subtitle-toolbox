<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class SnapCommand extends RemovedCommand
{
    private const RENAMES = [
        "shot-changes"        => "snap-shot-changes",
        "min-gap-frames"      => "snap-min-gap-frames",
        "min-duration-frames" => "snap-min-duration-frames",
        "no-chain"            => "snap-no-chain",
    ];


    public function name(): string
    {
        return "snap";
    }


    public function summary(): string
    {
        return "Removed. Use convert --snap-shot-changes FILE --video-fps RATE.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --video-fps RATE [--shot-changes FILE] [options]"];
    }


    protected function details(): string
    {
        return "snap exits with code 2 and prints the matching convert call. --shot-changes becomes --snap-shot-changes,\n" .
               "and each frame option gets the prefix snap-, for example --min-gap-frames becomes --snap-min-gap-frames.\n" .
               "--snap-window and --video-fps stay. A call without --shot-changes, --snap-window, the frame options and\n" .
               "--no-chain gets --snap-min-gap-frames 2, the default, so convert still closes small gaps.";
    }


    protected function replacement(array $arguments): string
    {
        $snaps = preg_grep('/^--(' . implode("|", array_keys(self::RENAMES)) . '|snap-window)(=|$)/', $arguments);

        return self::replacementCall("convert", $arguments, self::RENAMES, $snaps === [] ? ["--snap-min-gap-frames", "2"] : []);
    }
}
