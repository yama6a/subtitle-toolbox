<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class FixCommand extends RemovedCommand
{
    private const RENAMES = [
        "common-errors"    => "fix-common-errors",
        "replace-list"     => "fix-replace-list",
        "list-fixes"       => "fix-list",
        "resegment"        => "fix-resegment",
        "max-word-gap"     => "fix-max-word-gap",
        "overlaps"         => "fix-overlaps",
        "min-duration"     => "fix-min-duration",
        "min-gap"          => "fix-min-gap",
        "wrap"             => "fix-wrap",
        "max-lines"        => "fix-max-lines",
        "unwrap"           => "fix-unwrap",
        "merge-duplicates" => "fix-merge-duplicates",
        "merge-short"      => "fix-merge-short",
        "split-long"       => "fix-split-long",
        "max-cpl"          => "fix-max-cpl",
    ];


    public function name(): string
    {
        return "fix";
    }


    public function summary(): string
    {
        return "Removed. Use convert with the --fix- options.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--overlaps] [--wrap CHARS] [options]"];
    }


    protected function details(): string
    {
        return "fix exits with code 2 and prints the matching convert call. Each fix option gets the prefix fix-, for example\n" .
               "--overlaps becomes --fix-overlaps and --list-fixes becomes --fix-list. --language stays.";
    }


    protected function replacement(array $arguments): string
    {
        return self::replacementCall("convert", $arguments, self::RENAMES);
    }
}
