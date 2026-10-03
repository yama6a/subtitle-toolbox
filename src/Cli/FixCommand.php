<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Format;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\ResegmentOptions;
use SubtitleToolbox\Subtitle;

class FixCommand extends WriteCommand
{
    private const FIXES = ["common-errors", "resegment", "overlaps", "min-duration", "wrap", "unwrap", "merge-duplicates", "merge-short", "split-long"];

    private ?CommonErrorOptions $commonErrors = null;


    public function name(): string
    {
        return "fix";
    }


    public function summary(): string
    {
        return "Fixes text errors, overlapping cues, short cues and long lines, and regroups words into cues.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--common-errors] [--resegment] [--overlaps] [--min-duration SECONDS] [--wrap CHARS] [--unwrap] [--merge-duplicates] [--merge-short] [--split-long] [options]"];
    }


    protected function details(): string
    {
        return "Pass at least one fix. The fixes run in this order: --common-errors, --resegment, --unwrap, --merge-short, --split-long, --wrap,\n" .
               "--merge-duplicates, --overlaps, --min-duration. The timing fixes move only end times. Without --output, --output-dir or\n" .
               "--in-place, the result of one input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::flag("common-errors", "Fix spacing, punctuation, dash, tag and OCR errors such as lt's for It's."),
            Option::value("language", "CODE", "Language rules for --common-errors, for example en or de-AT. Default: the language of the input."),
            Option::value("replace-list", "FILE", "Also apply this Subtitle Edit OCR replace list, an XML file, with --common-errors."),
            Option::flag("list-fixes", "Print each change of --common-errors to standard error."),
            Option::flag("resegment", "Build new cues from the word timestamps, one sentence or as much as fits --max-cpl and --max-lines each."),
            Option::value("max-word-gap", "SECONDS", "--resegment ends a cue at a pause of this length. Default: 0.6."),
            Option::flag("overlaps", "End each cue at least --min-gap seconds before the next cue starts."),
            Option::value("min-duration", "SECONDS", "Show each cue for at least this time where the next cue allows it."),
            Option::value("min-gap", "SECONDS", "Gap between cues for --overlaps and --min-duration. Default: 0."),
            Option::value("wrap", "CHARS", "Break lines longer than this number of characters."),
            Option::value("max-lines", "LINES", "Maximum number of lines per cue for --wrap, --resegment, --merge-short and --split-long. Default: 2."),
            Option::flag("unwrap", "Join the lines of each cue with a space."),
            Option::flag("merge-duplicates", "Join touching cues with the same text."),
            Option::flag("merge-short", "Join cues shorter than 1 s with a neighbour at most 0.25 s away, where the joined cue fits 7 s, --max-cpl and --max-lines."),
            Option::flag("split-long", "Split cues longer than 7 s, or longer than --max-lines lines of --max-cpl characters, at sentence ends, clause ends or spaces."),
            Option::value("max-cpl", "CHARS", "Maximum characters per line for --resegment, --merge-short and --split-long. Default: 42."),
        ];
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return parent::needsWordTimestamps($arguments) || $arguments->has("resegment");
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        if (array_filter(self::FIXES, $arguments->has(...)) === []) {
            self::fail("Pass at least one fix: --" . implode(", --", self::FIXES) . ".");
        }
        foreach (["language", "replace-list", "list-fixes"] as $option) {
            if ($arguments->has($option) && !$arguments->has("common-errors")) {
                self::fail("Pass --common-errors with --$option.");
            }
        }
        $this->commonErrors = $arguments->has("common-errors") ? new CommonErrorOptions(
            language: $arguments->value("language"),
            replaceList: self::loadReplaceList($arguments->value("replace-list")),
        ) : null;
        if ($arguments->has("max-word-gap") && !$arguments->has("resegment")) {
            self::fail("Pass --resegment with --max-word-gap.");
        }
        $arguments->positiveFloat("max-word-gap");
        $arguments->positiveFloat("min-duration");
        $arguments->positiveInt("wrap");
        $arguments->positiveInt("max-lines");
        $arguments->positiveInt("max-cpl");
        if (($arguments->float("min-gap") ?? 0) < 0) {
            self::fail("The option --min-gap must not be negative.");
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        if ($this->commonErrors !== null) {
            foreach (CommonErrorFixer::fix($subtitle, $this->commonErrors) as $fix) {
                if ($arguments->has("list-fixes")) {
                    $console->err(self::label($input) . ": cue " . ($fix->cueIndex + 1) . ": $fix->rule: " .
                                  json_encode($fix->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " -> " .
                                  json_encode($fix->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                }
            }
        }

        parent::process($input, $subtitle, $format, $arguments, $console);
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        $minGap = $arguments->float("min-gap") ?? 0;

        if ($arguments->has("resegment")) {
            $subtitle->resegmentByWords(new ResegmentOptions(
                maxCharactersPerLine: $arguments->positiveInt("max-cpl") ?? 42,
                maxLines: $arguments->positiveInt("max-lines") ?? 2,
                maxWordGap: $arguments->positiveFloat("max-word-gap") ?? 0.6,
            ));
        }
        if ($arguments->has("unwrap")) {
            $subtitle->unwrapLines();
        }
        if ($arguments->has("merge-short")) {
            $subtitle->mergeShortCues(new MergeShortCuesOptions(
                maxCharactersPerLine: $arguments->positiveInt("max-cpl") ?? 42,
                maxLines: $arguments->positiveInt("max-lines") ?? 2,
            ));
        }
        if ($arguments->has("split-long")) {
            $subtitle->splitLongCues(new ResegmentOptions(
                maxCharactersPerLine: $arguments->positiveInt("max-cpl") ?? 42,
                maxLines: $arguments->positiveInt("max-lines") ?? 2,
            ));
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


    private static function loadReplaceList(?string $path): ?OcrReplaceList
    {
        if ($path === null) {
            return null;
        }
        $xml = is_file($path) ? @file_get_contents($path) : false;
        if ($xml === false) {
            self::fail("Cannot read the replace list $path.");
        }

        try {
            return OcrReplaceList::fromSubtitleEditXml($xml);
        } catch (ParsingException $exception) {
            return self::fail("$path: " . $exception->getMessage());
        }
    }
}
