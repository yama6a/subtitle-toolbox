<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class DualCommand extends WriteCommand
{
    private const MODES = ["stack" => DualSubtitleMode::Stack, "top-bottom" => DualSubtitleMode::TopBottom];

    private ?DualSubtitleOptions $dualOptions = null;


    public function name(): string
    {
        return "dual";
    }


    public function summary(): string
    {
        return "Merges two subtitles in two languages into one file that shows both.";
    }


    protected function usageLines(): array
    {
        return ["<primary> <secondary> [options]"];
    }


    protected function details(): string
    {
        return "stack joins each secondary cue with the primary cue that it overlaps most, below its lines.\n" .
               "top-bottom keeps both cues and moves the secondary one to the top. SubRip, WebVTT, ASS and TTML write\n" .
               "the position. The output takes the format of the primary file unless --to or the --output extension sets\n" .
               "it. Without --output, --output-dir or --in-place, the result goes to standard output. --from and --track\n" .
               "apply to the primary file, --from2 and --track2 to the secondary file. --in-place overwrites the primary file.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("mode", "MODE", "stack or top-bottom. Default: stack."),
            Option::value("secondary-style", "TAG", "Tag around each secondary line: b, i, u, s or 'font color=\"#ffff00\"'. Default: none."),
            Option::value("secondary-alignment", "1-9", "Position of the secondary cues for top-bottom, as on a numeric keypad. Default: 8."),
            Option::value("snap-tolerance", "SECONDS", "top-bottom moves a secondary time to a primary time this close. Default: 0.25."),
        ];
    }


    protected function inputOptions(): array
    {
        return [...parent::inputOptions(), ...self::secondFileOptions("secondary")];
    }


    protected function inputArguments(Arguments $arguments): array
    {
        if (count($arguments->positionals) !== 2) {
            self::fail("Pass two files, the primary one and the secondary one.");
        }

        return [$arguments->positionals[0]];
    }


    protected function readPaths(array $inputs, Arguments $arguments): array
    {
        return [...$inputs, $arguments->positionals[1]];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $mode = $arguments->value("mode") ?? "stack";
        if (!isset(self::MODES[$mode])) {
            self::fail("Unknown mode \"$mode\". Known modes: " . implode(", ", array_keys(self::MODES)) . ".");
        }
        $alignment = $arguments->value("secondary-alignment") ?? "8";
        if (!in_array($alignment, ["1", "2", "3", "4", "5", "6", "7", "8", "9"], true)) {
            self::fail("The option --secondary-alignment needs a number from 1 to 9, got \"$alignment\".");
        }
        if (($arguments->float("snap-tolerance") ?? 0) < 0) {
            self::fail("The option --snap-tolerance must not be negative.");
        }

        try {
            $this->dualOptions = new DualSubtitleOptions(
                mode: self::MODES[$mode],
                snapTolerance: $arguments->float("snap-tolerance") ?? 0.25,
                secondaryStyle: $arguments->value("secondary-style"),
                secondaryAlignment: (int)$alignment,
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $secondary = $this->loadSecondFile($arguments->positionals[1], $arguments);

        parent::process($input, DualSubtitle::merge($subtitle, $secondary, $this->dualOptions), $format, $arguments, $console);
    }
}
