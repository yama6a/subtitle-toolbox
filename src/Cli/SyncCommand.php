<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

class SyncCommand extends WriteCommand
{
    private const LOW_SCORE = 0.5;

    private ?ReferenceSyncOptions $syncOptions = null;

    private ?Subtitle $reference = null;


    public function name(): string
    {
        return "sync";
    }


    public function summary(): string
    {
        return "Finds the offset and frame-rate scale against a reference subtitle and retimes the input.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --reference FILE [options]"];
    }


    protected function details(): string
    {
        return "Only the cue times count, so the reference can be in another language. The scale is 1 or a factor\n" .
               "between 23.976, 24 and 25 fps. The tool prints the scale, the offset and a score from 0 to 1. A score\n" .
               "below 0.5 means that the files likely do not match. Without --output, --output-dir or --in-place, the\n" .
               "result of one input file goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::value("reference", "FILE", "Subtitle in sync with the video, in any format that the tool reads."),
            Option::value("min-offset", "SECONDS", "Smallest offset to try. Default: -60."),
            Option::value("max-offset", "SECONDS", "Largest offset to try. Default: 60."),
            Option::flag("no-scale", "Keep the scale at 1 and find only the offset."),
            Option::value("max-splits", "SPLITS", "Find up to this many points where the offset jumps, for example at ad breaks. Default: 0."),
            Option::value("split-penalty", "SCORE", "Score that a split must add to stay. Default: 0.1."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->reference = null;
        $this->checkReference($arguments);

        $maxSplits = $arguments->value("max-splits") ?? "0";
        if (!ctype_digit($maxSplits)) {
            self::fail("The option --max-splits needs a whole number, got \"$maxSplits\".");
        }
        if (($arguments->float("split-penalty") ?? 0) < 0) {
            self::fail("The option --split-penalty must not be negative.");
        }

        try {
            $this->syncOptions = new ReferenceSyncOptions(
                minOffset: $arguments->float("min-offset") ?? -60,
                maxOffset: $arguments->float("max-offset") ?? 60,
                searchScale: !$arguments->has("no-scale"),
                maxSplits: (int)$maxSplits,
                splitPenalty: $arguments->float("split-penalty") ?? 0.1,
            );
        } catch (InvalidArgumentException $exception) {
            self::fail($exception->getMessage());
        }
    }


    protected function checkReference(Arguments $arguments): void
    {
        if (!$arguments->has("reference")) {
            self::fail("Pass --reference FILE.");
        }
    }


    protected function loadReference(Arguments $arguments, Console $console): Subtitle
    {
        return $this->readSecondFile($arguments->value("reference"), $arguments, $console);
    }


    protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void
    {
        $this->reference ??= $this->loadReference($arguments, $console);

        $result = ReferenceSync::sync($subtitle, $this->reference, $this->syncOptions);
        $result->apply($subtitle);

        $label = self::label($input);
        $text  = "$label: scale " . self::number($result->getScale(), 5) . ", offset " . self::number($result->getOffset(), 3) .
                 " s, score " . self::number($result->getScore(), 2) . "\n";
        $segments = $result->getSegments();
        if (count($segments) > 1) {
            foreach ($segments as $segment) {
                $text .= "$label: from " . self::number($segment["from"], 3) . " s: offset " . self::number($segment["offset"], 3) . " s\n";
            }
        }
        $console->err($text);
        if ($result->getScore() < self::LOW_SCORE) {
            $console->err("$label: the score is below " . self::LOW_SCORE . ", so the files likely do not match.\n");
        }

        parent::process($input, $subtitle, $format, $arguments, $console);
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
    }


    private static function number(float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals, ".", ""), "0"), ".");
    }
}
