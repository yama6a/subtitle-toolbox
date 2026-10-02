<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\SubtitleStatistics;

class InfoCommand extends ReportCommand
{
    public function name(): string
    {
        return "info";
    }


    public function summary(): string
    {
        return "Prints the format, the cue count and statistics of subtitle files.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--json] [options]"];
    }


    protected function details(): string
    {
        return "Times are in seconds. Characters leave out tags. The gap is the start of a cue minus the latest end of\n" .
               "the earlier cues, so an overlap gives a negative gap.";
    }


    protected function commandOptions(): array
    {
        return [];
    }


    protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void
    {
        $statistics = SubtitleStatistics::of($subtitle);

        $range = fn (array $values): string => "min " . self::number($values["min"]) . ", average " .
                                               self::number($values["average"]) . ", max " . self::number($values["max"]);
        $words = [];
        foreach ($statistics->getMostUsedWords(10) as $word => $count) {
            $words[] = "$word ($count)";
        }

        $imageCues         = array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue));
        $imageCuesWithText = count(array_filter($imageCues, fn (SubtitleCue $cue): bool => $cue->getLines() !== []));

        $rows = [
            "Format" => $format,
            "Cues"   => (string)$statistics->getCueCount(),
        ];
        if ($imageCues !== []) {
            $rows["Image cues"] = count($imageCues) . ", $imageCuesWithText with text";
        }
        $rows += [
            "Words"                 => (string)$statistics->getWordCount(),
            "Characters"            => (string)$statistics->getCharacterCount(),
            "Display time"          => self::number($statistics->getTotalDisplayTime()) . " s",
            "Span"                  => self::number($statistics->getSpan()) . " s",
            "Characters per second" => $range($statistics->getCharactersPerSecond()),
            "Words per minute"      => $range($statistics->getWordsPerMinute()),
            "Characters per line"   => $range($statistics->getCharactersPerLine()),
            "Gap"                   => $range($statistics->getGap()) . " s",
            "Most used words"       => implode(", ", $words),
        ];
        foreach ($subtitle->getAllMetadata() as $key => $value) {
            $rows["Metadata $key"] = $value;
        }

        $width = max(array_map("strlen", array_keys($rows)));
        $text  = self::label($input) . "\n";
        foreach ($rows as $name => $value) {
            $text .= "  " . str_pad("$name:", $width + 1) . " $value\n";
        }

        $data                  = $statistics->toArray();
        $data["mostUsedWords"] = (object)$data["mostUsedWords"];
        $this->emit($console, ($this->succeeded > 0 ? "\n" : "") . $text, [
            "file"       => self::label($input),
            "format"     => $format,
            "metadata"   => (object)$subtitle->getAllMetadata(),
            "statistics" => $data,
            "imageCues"  => ["count" => count($imageCues), "withText" => $imageCuesWithText],
        ]);
    }
}
