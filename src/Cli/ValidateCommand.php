<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Validation\ValidationResult;
use SubtitleToolbox\Validation\ValidationRules;

class ValidateCommand extends ReportCommand
{
    private const PRESETS = ["netflix-en"];

    private const DEFAULT_FPS = 23.976;

    private ?ValidationRules $rules = null;

    private int $withProblems = 0;


    public function name(): string
    {
        return "validate";
    }


    public function summary(): string
    {
        return "Checks the cues against reading speed, line length and timing rules.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --preset netflix-en [options]", "<input>... [--max-cpl CHARS] [--no-overlap] [...] [options]"];
    }


    protected function details(): string
    {
        return "Prints one line per broken rule. Cue numbers start at 1. The exit code is 1 when a file breaks a rule.\n" .
               "The netflix-en preset has the limits of the Netflix English (USA) Timed Text Style Guide: 20 characters\n" .
               "per second, 42 characters per line, 2 lines, 5/6 s to 7 s, a gap of 2 frames and no overlaps.\n" .
               "A rule option overrides the value of the preset.";
    }


    protected function fpsDescription(): string
    {
        return "Frame rate of the video, for the 2-frame gap of netflix-en and for MicroDVD input. Default: 23.976.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("preset", "NAME", "Rule set: netflix-en."),
            Option::value("max-cps", "CHARS", "Maximum characters per second."),
            Option::value("max-cpl", "CHARS", "Maximum characters per line."),
            Option::value("max-lines", "LINES", "Maximum lines per cue."),
            Option::value("min-duration", "SECONDS", "Minimum duration of a cue."),
            Option::value("max-duration", "SECONDS", "Maximum duration of a cue."),
            Option::value("min-gap", "SECONDS", "Minimum gap between cues."),
            Option::flag("no-overlap", "Report overlapping cues."),
            Option::flag("no-empty-cues", "Report cues without text."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->withProblems = 0;
        $preset             = $arguments->value("preset");
        if ($preset !== null && !in_array($preset, self::PRESETS, true)) {
            self::fail("Unknown preset \"$preset\". Known presets: " . implode(", ", self::PRESETS) . ".");
        }
        $base = $preset === null ? new ValidationRules() : ValidationRules::netflixEnglish($this->fps ?? self::DEFAULT_FPS);

        $this->rules = new ValidationRules(
            maxCharactersPerSecond: $arguments->positiveFloat("max-cps") ?? $base->maxCharactersPerSecond,
            maxCharactersPerLine: $arguments->positiveInt("max-cpl") ?? $base->maxCharactersPerLine,
            maxLinesPerCue: $arguments->positiveInt("max-lines") ?? $base->maxLinesPerCue,
            minDuration: $arguments->positiveFloat("min-duration") ?? $base->minDuration,
            maxDuration: $arguments->positiveFloat("max-duration") ?? $base->maxDuration,
            minGap: $arguments->positiveFloat("min-gap") ?? $base->minGap,
            noOverlap: $arguments->has("no-overlap") || $base->noOverlap,
            noEmptyCues: $arguments->has("no-empty-cues") || $base->noEmptyCues,
        );
        if ($this->rules == new ValidationRules()) {
            self::fail("Pass --preset or at least one rule option.");
        }
    }


    protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void
    {
        $results = $subtitle->validate($this->rules);
        if ($results !== []) {
            $this->withProblems++;
        }

        $label = self::label($input);
        $text  = $results === [] ? "$label: no problems\n" : "";
        foreach ($results as $result) {
            $limit = $result->getLimit() === null ? "" : ", limit " . self::number($result->getLimit());
            $text .= "$label: cue " . ($result->getCueIndex() + 1) . ": " . $result->getRule() . " " .
                     self::number($result->getValue()) . "$limit\n";
        }

        $this->emit($console, $text, [
            "file"    => $label,
            "format"  => $format,
            "valid"   => $results === [],
            "results" => array_map(fn (ValidationResult $result): array => [
                "cueIndex"  => $result->getCueIndex(),
                "cueNumber" => $result->getCueIndex() + 1,
                "rule"      => $result->getRule(),
                "value"     => self::jsonNumber($result->getValue()),
                "limit"     => self::jsonNumber($result->getLimit()),
            ], $results),
        ]);
    }


    protected function batchSummary(int $total, int $skipped): ?string
    {
        $valid   = $this->succeeded - $this->withProblems;
        $summary = "$total files: $valid valid, $this->withProblems with problems, $this->failed failed";

        return $summary . ($skipped > 0 ? ", $skipped skipped." : ".");
    }


    protected function exitCode(): int
    {
        return $this->failed > 0 || $this->withProblems > 0 ? Application::EXIT_FAILURE : Application::EXIT_OK;
    }
}
