<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class StripSdhCommand extends RemovedCommand
{
    private const RENAMES = [
        "keep-square-brackets" => "sdh-keep-square-brackets",
        "keep-parentheses"     => "sdh-keep-parentheses",
        "keep-speaker-labels"  => "sdh-keep-speaker-labels",
        "keep-music-lines"     => "sdh-keep-music-lines",
        "any-case-labels"      => "sdh-any-case-labels",
        "lyrics"               => "sdh-lyrics",
        "brackets"             => "sdh-brackets",
    ];


    public function name(): string
    {
        return "strip-sdh";
    }


    public function summary(): string
    {
        return "Removed. Use convert --sdh.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [options]"];
    }


    protected function details(): string
    {
        return "strip-sdh exits with code 2 and prints the matching convert call with --sdh. Each option gets the\n" .
               "prefix sdh-, for example --keep-parentheses becomes --sdh-keep-parentheses.";
    }


    protected function replacement(array $arguments): string
    {
        return self::replacementCall("convert", $arguments, self::RENAMES, ["--sdh"]);
    }
}
