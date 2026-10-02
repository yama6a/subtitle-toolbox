<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class FixCommand extends WriteCommand
{
    private const FIXES = ["overlaps", "min-duration", "wrap", "unwrap", "merge-duplicates"];


    public function name(): string
    {
        return "fix";
    }


    public function summary(): string
    {
        return "Fixes overlapping cues, short cues and long lines.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--overlaps] [--min-duration SECONDS] [--wrap CHARS] [--unwrap] [--merge-duplicates] [options]"];
    }


    protected function details(): string
    {
        return "Pass at least one fix. The fixes run in this order: --unwrap, --wrap, --merge-duplicates, --overlaps,\n" .
               "--min-duration. The timing fixes move only end times. Without --output, --output-dir or --in-place, the\n" .
               "result of one input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::flag("overlaps", "End each cue at least --min-gap seconds before the next cue starts."),
            Option::value("min-duration", "SECONDS", "Show each cue for at least this time where the next cue allows it."),
            Option::value("min-gap", "SECONDS", "Gap between cues for --overlaps and --min-duration. Default: 0."),
            Option::value("wrap", "CHARS", "Break lines longer than this number of characters."),
            Option::value("max-lines", "LINES", "Maximum number of lines per cue for --wrap. Default: 2."),
            Option::flag("unwrap", "Join the lines of each cue with a space."),
            Option::flag("merge-duplicates", "Join touching cues with the same text."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        if (array_filter(self::FIXES, $arguments->has(...)) === []) {
            self::fail("Pass at least one fix: --" . implode(", --", self::FIXES) . ".");
        }
        $arguments->positiveFloat("min-duration");
        $arguments->positiveInt("wrap");
        $arguments->positiveInt("max-lines");
        if (($arguments->float("min-gap") ?? 0) < 0) {
            self::fail("The option --min-gap must not be negative.");
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $minGap = $arguments->float("min-gap") ?? 0;

        if ($arguments->has("unwrap")) {
            $subtitle->unwrapLines();
        }
        if ($arguments->has("wrap")) {
            $subtitle->wrapLines($arguments->positiveInt("wrap"), $arguments->positiveInt("max-lines") ?? 2);
        }
        if ($arguments->has("merge-duplicates")) {
            $subtitle->removeDuplicateCues();
        }
        if ($arguments->has("overlaps")) {
            $subtitle->fixOverlaps($minGap);
        }
        if ($arguments->has("min-duration")) {
            $subtitle->extendShortCues($arguments->positiveFloat("min-duration"), $minGap);
        }
    }
}
