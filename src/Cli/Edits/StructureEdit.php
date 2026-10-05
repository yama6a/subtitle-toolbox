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

/**
 * @internal
 */
final class StructureEdit extends Edit
{
    private const EDITS = ["structure-resegment", "structure-unwrap", "structure-merge-short", "structure-split-long", "structure-wrap", "structure-merge-duplicates"];


    private function __construct(
        private readonly ?float $resegmentWordGap,
        private readonly bool $unwrap,
        private readonly bool $mergeShort,
        private readonly bool $splitLong,
        private readonly bool $wrap,
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
            Option::flag("structure-resegment", "Build new cues from the word timestamps, one sentence or as much as fits --structure-max-cpl and --structure-max-lines each."),
            Option::value("structure-max-word-gap", "SECONDS", "--structure-resegment ends a cue at a pause of this length. Default: 0.6."),
            Option::flag("structure-unwrap", "Join the lines of each cue with a space."),
            Option::flag("structure-merge-short", "Join cues shorter than 1 s with a neighbor at most 0.25 s away, where the joined cue fits 7 s, --structure-max-cpl and --structure-max-lines."),
            Option::flag("structure-split-long", "Split cues longer than 7 s, or longer than --structure-max-lines lines of --structure-max-cpl characters, at sentence ends, clause ends or spaces."),
            Option::flag("structure-wrap", "Break lines longer than --structure-max-cpl characters."),
            Option::value("structure-max-cpl", "CHARS", "Maximum characters per line for --structure-wrap, --structure-resegment, --structure-merge-short and --structure-split-long. Default: 42."),
            Option::value("structure-max-lines", "LINES", "Maximum number of lines per cue for --structure-wrap, --structure-resegment, --structure-merge-short and --structure-split-long. Default: 2."),
            Option::flag("structure-merge-duplicates", "Join touching cues with the same text."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "structure-resegment", ["structure-max-word-gap"]);
        self::needsOneOf($arguments, ["structure-wrap", "structure-resegment", "structure-merge-short", "structure-split-long"], "structure-max-cpl");
        self::needsOneOf($arguments, ["structure-wrap", "structure-resegment", "structure-merge-short", "structure-split-long"], "structure-max-lines");
        $wordGap  = $arguments->positiveFloat("structure-max-word-gap");
        $maxCpl   = $arguments->positiveInt("structure-max-cpl");
        $maxLines = $arguments->positiveInt("structure-max-lines");
        if (array_filter(self::EDITS, $arguments->has(...)) === []) {
            return null;
        }

        return new self(
            $arguments->has("structure-resegment") ? $wordGap ?? 0.6 : null,
            $arguments->has("structure-unwrap"),
            $arguments->has("structure-merge-short"),
            $arguments->has("structure-split-long"),
            $arguments->has("structure-wrap"),
            $arguments->has("structure-merge-duplicates"),
            $maxCpl ?? 42,
            $maxLines ?? 2,
        );
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $limits = new CueLimits(maxCharactersPerLine: $this->maxCharactersPerLine, maxLinesPerCue: $this->maxLines);
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
        if ($this->wrap) {
            $subtitle->wrapLines($this->maxCharactersPerLine, $this->maxLines);
        }
        if ($this->mergeDuplicates) {
            $subtitle->removeDuplicateCues();
        }

        return $subtitle;
    }
}
