<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleMode;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class DualCommand extends WriteCommand
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
        return ["--primary FILE --secondary FILE [options]"];
    }


    protected function details(): string
    {
        return "stack joins each secondary cue with the primary cue that it overlaps most, below its lines.\n" .
               "top-bottom keeps both cues and moves the secondary one to the top. SubRip, WebVTT, ASS and TTML write\n" .
               "the position. The output takes the format of the primary file unless --to sets it. The result goes to\n" .
               "standard output, or to the file of -o.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("primary", "FILE", "The subtitle whose cues set the times, or - for standard input."),
            Option::value("secondary", "FILE", "The subtitle in the second language."),
            Option::value("mode", "MODE", "stack or top-bottom. Default: stack."),
            Option::value("secondary-style", "TAG", "Tag around each secondary line: b, i, u, s or 'font color=\"#ffff00\"'. Default: none."),
            Option::value("secondary-alignment", "1-9", "Position of the secondary cues for top-bottom, as on a numeric keypad. Default: 8."),
            Option::value("snap-tolerance", "SECONDS", "top-bottom moves a secondary time to a primary time this close. Default: 0.25."),
        ];
    }


    protected function toDescription(): string
    {
        return "Output format. Default: the format of the primary file.";
    }


    protected function fileOptionNames(): array
    {
        return ["from" => "primary-from", "track" => "primary-track", "from2" => "secondary-from", "track2" => "secondary-track"];
    }


    protected function takesManyInputs(): bool
    {
        return false;
    }


    protected function inputOptions(): array
    {
        $options = [];
        foreach (parent::inputOptions() as $option) {
            $options[] = match ($option->name) {
                "from"  => Option::value("primary-from", "FORMAT", "Format of the primary file. Default: detected from the content, else taken from the file extension."),
                "track" => Option::value("primary-track", "NUMBER", "Subtitle track of an MKV or WebM primary file. Needed when the file has several."),
                default => $option,
            };
        }

        return [...$options, ...$this->secondFileOptions("secondary")];
    }


    protected function inputArguments(Arguments $arguments): array
    {
        if ($arguments->positionals !== []) {
            self::fail("dual takes no file arguments. Pass --primary FILE and --secondary FILE.");
        }
        if (!$arguments->has("primary") || !$arguments->has("secondary")) {
            self::fail("Pass --primary FILE and --secondary FILE.");
        }

        return [$arguments->value("primary")];
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1) {
            self::fail("--primary takes one file, got " . count($inputs) . ".");
        }

        parent::checkInputs($inputs, $arguments);
    }


    protected function readPaths(array $inputs, Arguments $arguments): array
    {
        return [...$inputs, $arguments->value("secondary")];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $mode              = $arguments->choice("mode", array_keys(self::MODES));
        $this->dualOptions = new DualSubtitleOptions(...self::given([
            "mode"               => $mode === null ? null : self::MODES[$mode],
            "snapTolerance"      => $arguments->nonNegativeFloat("snap-tolerance"),
            "secondaryStyle"     => $arguments->value("secondary-style"),
            "secondaryAlignment" => $arguments->int("secondary-alignment", 1, 9),
        ]));
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $secondary = $this->loadSecondFile($arguments->value("secondary"), $arguments, $console);

        parent::process($input, DualSubtitle::fromPair($subtitle, $secondary, $this->dualOptions), $format, $arguments, $console);
    }
}
