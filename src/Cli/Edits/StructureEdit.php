<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\CueLimits;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Resegmenting\ResegmentMode;
use SubtitleToolbox\Resegmenting\Resegmenter;
use SubtitleToolbox\Resegmenting\ResegmentOptions;
use SubtitleToolbox\Subtitle;

final class StructureEdit extends Edit
{
    private const EDITS = ["fix-resegment", "fix-unwrap", "fix-merge-short", "fix-split-long", "fix-wrap", "fix-merge-duplicates"];


    private function __construct(
        private readonly ?float $resegmentWordGap,
        private readonly bool $unwrap,
        private readonly bool $mergeShort,
        private readonly bool $splitLong,
        private readonly ?int $wrap,
        private readonly bool $mergeDuplicates,
        private readonly int $maxCharactersPerLine,
        private readonly int $maxLines,
    ) {
    }


    public static function group(): string
    {
        return "structure";
    }


    public static function summary(): string
    {
        return "Rebuild, join, split and wrap cues.";
    }


    public static function options(): array
    {
        return [
            Option::flag("fix-resegment", "Build new cues from the word timestamps, one sentence or as much as fits --fix-max-cpl and --fix-max-lines each."),
            Option::value("fix-max-word-gap", "SECONDS", "--fix-resegment ends a cue at a pause of this length. Default: 0.6."),
            Option::flag("fix-unwrap", "Join the lines of each cue with a space."),
            Option::flag("fix-merge-short", "Join cues shorter than 1 s with a neighbour at most 0.25 s away, where the joined cue fits 7 s, --fix-max-cpl and --fix-max-lines."),
            Option::flag("fix-split-long", "Split cues longer than 7 s, or longer than --fix-max-lines lines of --fix-max-cpl characters, at sentence ends, clause ends or spaces."),
            Option::value("fix-wrap", "CHARS", "Break lines longer than this number of characters."),
            Option::value("fix-max-cpl", "CHARS", "Maximum characters per line for --fix-resegment, --fix-merge-short and --fix-split-long. Default: 42."),
            Option::value("fix-max-lines", "LINES", "Maximum number of lines per cue for --fix-wrap, --fix-resegment, --fix-merge-short and --fix-split-long. Default: 2."),
            Option::flag("fix-merge-duplicates", "Join touching cues with the same text."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "fix-resegment", ["fix-max-word-gap"]);
        self::needsOneOf($arguments, ["fix-resegment", "fix-merge-short", "fix-split-long"], "fix-max-cpl");
        self::needsOneOf($arguments, ["fix-wrap", "fix-resegment", "fix-merge-short", "fix-split-long"], "fix-max-lines");
        $wordGap  = $arguments->positiveFloat("fix-max-word-gap");
        $wrap     = $arguments->positiveInt("fix-wrap");
        $maxCpl   = $arguments->positiveInt("fix-max-cpl");
        $maxLines = $arguments->positiveInt("fix-max-lines");
        if (array_filter(self::EDITS, $arguments->has(...)) === []) {
            return null;
        }

        return new self(
            $arguments->has("fix-resegment") ? $wordGap ?? 0.6 : null,
            $arguments->has("fix-unwrap"),
            $arguments->has("fix-merge-short"),
            $arguments->has("fix-split-long"),
            $wrap,
            $arguments->has("fix-merge-duplicates"),
            $maxCpl ?? 42,
            $maxLines ?? 2,
        );
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $limits = new CueLimits(maxCharactersPerLine: $this->maxCharactersPerLine, maxLines: $this->maxLines);
        if ($this->resegmentWordGap !== null) {
            Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::ByWords, $limits, $this->resegmentWordGap));
        }
        if ($this->unwrap) {
            $subtitle->unwrapLines();
        }
        if ($this->mergeShort) {
            $subtitle->mergeShortCues(new MergeShortCuesOptions($limits));
        }
        if ($this->splitLong) {
            Resegmenter::apply($subtitle, new ResegmentOptions(ResegmentMode::SplitLong, $limits));
        }
        if ($this->wrap !== null) {
            $subtitle->wrapLines($this->wrap, $this->maxLines);
        }
        if ($this->mergeDuplicates) {
            $subtitle->removeDuplicateCues();
        }

        return $subtitle;
    }
}
