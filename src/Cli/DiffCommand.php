<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Diff\CueDifference;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class DiffCommand extends ReportCommand
{
    private ?SubtitleDiffOptions $diffOptions = null;

    private bool $different = false;


    public function name(): string
    {
        return "diff";
    }


    public function summary(): string
    {
        return "Lists the added, removed and changed cues between two subtitle files.";
    }


    protected function usageLines(): array
    {
        return ["<old> <new> [options]"];
    }


    protected function details(): string
    {
        return "The diff pairs cues by time and text, not by cue number, so one added cue does not shift the rest.\n" .
               "Cue numbers start at 1. The exit code is 1 when the files differ, as with diff. The files can have\n" .
               "different formats. --from and --track apply to the old file.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("time-tolerance", "SECONDS", "A larger difference of a start or end time is a timing change. Default: 0.001."),
            Option::flag("ignore-formatting", "Compare the text without tags."),
            Option::flag("ignore-whitespace", "Compare the text without spaces, tabs and line breaks."),
            Option::flag("text-only", "Report no timing changes."),
        ];
    }


    protected function inputOptions(): array
    {
        return array_values(array_filter(parent::inputOptions(), fn (Option $option): bool => $option->name !== "keep-going"));
    }


    protected function inputArguments(Arguments $arguments): array
    {
        if (count($arguments->positionals) !== 2) {
            self::fail("Pass two files, the old one and the new one.");
        }

        return [$arguments->positionals[0]];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->different = false;
        if (($arguments->float("time-tolerance") ?? 0) < 0) {
            self::fail("The option --time-tolerance must not be negative.");
        }

        try {
            $this->diffOptions = new SubtitleDiffOptions(
                timeTolerance: $arguments->float("time-tolerance") ?? 0.001,
                ignoreFormatting: $arguments->has("ignore-formatting"),
                ignoreWhitespace: $arguments->has("ignore-whitespace"),
                textOnly: $arguments->has("text-only"),
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $newPath     = $arguments->positionals[1];
        $differences = SubtitleDiff::compare($subtitle, $this->readSecondFile($newPath, $arguments, $console), $this->diffOptions);

        $this->different = $differences !== [];
        $this->emit($console, SubtitleDiff::toText($differences), [
            "old"         => self::label($input),
            "new"         => self::label($newPath),
            "equal"       => $differences === [],
            "differences" => array_map(fn (CueDifference $difference): array => [
                "kind"     => $difference->getKind(),
                "oldIndex" => $difference->getOldIndex(),
                "newIndex" => $difference->getNewIndex(),
                "old"      => self::cue($difference->getOldCue()),
                "new"      => self::cue($difference->getNewCue()),
            ], $differences),
        ]);
    }


    protected function exitCode(): int
    {
        return $this->failed > 0 || $this->different ? Application::EXIT_FAILURE : Application::EXIT_OK;
    }


    /**
     * @return array{start: float, end: float, lines: list<string>, forced: bool}|null
     */
    private static function cue(?SubtitleCue $cue): ?array
    {
        return $cue === null ? null : [
            "start"  => $cue->getStart(),
            "end"    => $cue->getEnd(),
            "lines"  => $cue->getLines(),
            "forced" => $cue->isForced(),
        ];
    }
}
