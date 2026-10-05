<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Validation\ValidationViolation;
use SubtitleToolbox\Validation\ValidationRules;

/**
 * @internal
 */
final class ValidateCommand extends ReportCommand
{
    private const PRESETS = ["netflix-en", "bbc"];

    private const DEFAULT_FPS = 23.976;

    private ?ValidationRules $rules = null;

    private int $withProblems = 0;


    public function name(): string
    {
        return "validate";
    }


    public function summary(): string
    {
        return "Checks the cues against reading speed, line length, timing and text rules.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --preset netflix-en|bbc [options]", "<input>... [--max-cpl CHARS] [--check-overlaps] [...] [options]"];
    }


    protected function details(): string
    {
        return "Prints one line per broken rule. Cue numbers start at 1. The exit code is 1 when a file breaks a rule.\n" .
               "The netflix-en preset has the limits of the Netflix English (USA) Timed Text Style Guide: 20 characters\n" .
               "per second, 42 characters per line, 2 lines, 5/6 s to 7 s, a gap of 2 frames and no overlaps.\n" .
               "The bbc preset has the limits of the BBC Subtitle Guidelines: 37 characters per line, 180 words per\n" .
               "minute and 0.3 s per word.\n" .
               "A rule option overrides the value of the preset.";
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps and --video-fps. Each of them overrides it.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("preset", "NAME", "Rule set: netflix-en or bbc."),
            Option::value("video-fps", "RATE", "Frame rate of the video, for the 2-frame gap of netflix-en. Default: 23.976."),
            Option::value("max-cps", "CHARS", "Maximum characters per second."),
            Option::value("max-cpl", "CHARS", "Maximum characters per line."),
            Option::value("max-lines", "LINES", "Maximum lines per cue."),
            Option::value("min-duration", "SECONDS", "Minimum duration of a cue."),
            Option::value("max-duration", "SECONDS", "Maximum duration of a cue."),
            Option::value("min-gap", "SECONDS", "Minimum gap between cues."),
            Option::flag("check-overlaps", "Report overlapping cues."),
            Option::flag("check-empty-cues", "Report cues without text."),
            Option::value("max-wpm", "WORDS", "Maximum words per minute."),
            Option::value("min-seconds-per-word", "SECONDS", "Minimum duration of a cue per word."),
            Option::value("max-speakers", "SPEAKERS", "Maximum speakers per cue, from dialogue dashes or <v> names."),
            Option::value("dialogue-dash", "STYLE", "Report dialogue dashes in another style than STYLE, for example \"- \" or \"-\"."),
            Option::value("allowed-characters", "CHARS", "Report other characters. CHARS is a list or a class such as \"[A-Za-z0-9 .,!?]\"."),
            Option::flag("check-double-spaces", "Report two or more spaces between words."),
            Option::flag("check-leading-or-trailing-spaces", "Report lines that start or end with a space."),
            Option::flag("check-unbalanced-tags", "Report formatting tags without a partner tag."),
            Option::flag("check-all-caps-lines", "Report lines in upper case only."),
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
        if ($arguments->has("video-fps") && $preset !== "netflix-en") {
            self::fail("--video-fps sets the frame rate of the netflix-en gap rule. Pass --preset netflix-en, or leave out --video-fps.");
        }
        $base = match ($preset) {
            null         => new ValidationRules(),
            "bbc"        => ValidationRules::bbc(),
            "netflix-en" => ValidationRules::netflixEnglish(self::rate($arguments, "video-fps") ?? self::DEFAULT_FPS),
        };

        $this->rules = new ValidationRules(
            maxCharactersPerSecond: $arguments->positiveFloat("max-cps") ?? $base->maxCharactersPerSecond,
            maxCharactersPerLine: $arguments->positiveInt("max-cpl") ?? $base->maxCharactersPerLine,
            maxLinesPerCue: $arguments->positiveInt("max-lines") ?? $base->maxLinesPerCue,
            minDuration: $arguments->positiveFloat("min-duration") ?? $base->minDuration,
            maxDuration: $arguments->positiveFloat("max-duration") ?? $base->maxDuration,
            minGap: $arguments->positiveFloat("min-gap") ?? $base->minGap,
            noOverlap: $arguments->has("check-overlaps") || $base->noOverlap,
            noEmptyCues: $arguments->has("check-empty-cues") || $base->noEmptyCues,
            noDoubleSpaces: $arguments->has("check-double-spaces") || $base->noDoubleSpaces,
            noLeadingOrTrailingSpaces: $arguments->has("check-leading-or-trailing-spaces") || $base->noLeadingOrTrailingSpaces,
            noUnbalancedTags: $arguments->has("check-unbalanced-tags") || $base->noUnbalancedTags,
            dialogueDashStyle: self::dialogueDashStyle($arguments->value("dialogue-dash")) ?? $base->dialogueDashStyle,
            maxSpeakersPerCue: $arguments->positiveInt("max-speakers") ?? $base->maxSpeakersPerCue,
            maxWordsPerMinute: $arguments->positiveFloat("max-wpm") ?? $base->maxWordsPerMinute,
            minSecondsPerWord: $arguments->positiveFloat("min-seconds-per-word") ?? $base->minSecondsPerWord,
            allowedCharacters: $arguments->value("allowed-characters") ?? $base->allowedCharacters,
            noAllCapsLines: $arguments->has("check-all-caps-lines") || $base->noAllCapsLines,
        );
        if ($this->rules == new ValidationRules()) {
            self::fail("Pass --preset or at least one rule option.");
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $violations = $subtitle->validate($this->rules);
        if ($violations !== []) {
            $this->withProblems++;
        }

        $label = self::label($input);
        $text  = $violations === [] ? "$label: no problems\n" : "";
        foreach ($violations as $violation) {
            $limit = $violation->limit === null ? "" : ", limit " . self::number($violation->limit);
            $text .= "$label: cue " . ($violation->cueIndex + 1) . ": " . $violation->rule->value . " " .
                     self::number($violation->value) . "$limit\n";
        }

        $this->emit($console, $text, [
            "file"       => $label,
            "format"     => $format->value,
            "valid"      => $violations === [],
            "violations" => array_map(fn (ValidationViolation $violation): array => [
                "cueIndex" => $violation->cueIndex,
                "rule"     => $violation->rule->value,
                "value"    => self::jsonNumber($violation->value),
                "limit"    => self::jsonNumber($violation->limit),
            ], $violations),
            "warnings"   => self::warningsJson($this->parseWarnings),
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
        return match (true) {
            $this->failed > 0       => Application::EXIT_FILE,
            $this->withProblems > 0 => Application::EXIT_RESULT,
            default                 => Application::EXIT_OK,
        };
    }


    private static function dialogueDashStyle(?string $style): ?DialogueDashStyle
    {
        if ($style === null) {
            return null;
        }

        return DialogueDashStyle::tryFrom($style) ?? self::fail("The option --dialogue-dash must be a hyphen, an en " .
            "dash or an em dash, with or without one space after it, got \"$style\".");
    }
}
