<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Matroska\MatroskaTrack;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\SubtitleStatistics;

/**
 * @internal
 */
final class InfoCommand extends ReportCommand
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
        return "Times are in seconds. Characters leave out tags. " .
               "The gap is the start of a cue minus the latest end of the earlier cues, so an overlap gives a negative gap. " .
               "For an MKV or WebM file, info lists the subtitle tracks. Pass --track for the statistics of one track.";
    }


    protected function commandOptions(): array
    {
        return [];
    }


    protected function listTracks(string $path, string $input, Console $console): bool
    {
        if (Format::fromPath($input) !== null) {
            return false;
        }
        try {
            $tracks = Subtitle::tracks($path);
        } catch (ParsingException) {
            return false;
        }

        $text = self::label($input) . "\n  Container: matroska\n";
        foreach ($tracks as $track) {
            $text .= "  Track $track->number: " . $track->describe() . "\n";
        }
        $this->emit($console, ($this->succeeded > 0 ? "\n" : "") . $text, [
            "file"      => $input,
            "container" => "matroska",
            "tracks"    => array_map(fn (MatroskaTrack $track): array => [
                "number"   => $track->number,
                "codecId"  => $track->codecId,
                "language" => $track->language,
                "name"     => $track->name,
                "default"  => $track->default,
                "forced"   => $track->forced,
            ], $tracks),
        ]);

        return true;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $statistics = SubtitleStatistics::of($subtitle);

        $range = fn (?array $values, string $unit = ""): string => $values === null ? "-" :
            "min " . self::number($values["min"]) . ", average " . self::number($values["average"]) . ", max " .
            self::number($values["max"]) . $unit;
        $words = [];
        foreach (array_slice($statistics->mostUsedWords, 0, 10) as ["word" => $word, "count" => $count]) {
            $words[] = "$word ($count)";
        }

        $imageCues         = array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue));
        $imageCuesWithText = count(array_filter($imageCues, fn (SubtitleCue $cue): bool => $cue->getLines() !== []));

        $rows = [
            "Format" => $format->value,
            "Cues"   => (string)$statistics->cueCount,
        ];
        if ($this->parseWarnings !== []) {
            $rows["Warnings"] = (string)count($this->parseWarnings);
        }
        if ($imageCues !== []) {
            $rows["Image cues"] = count($imageCues) . ", $imageCuesWithText with text";
        }
        $rows += [
            "Words"                 => (string)$statistics->wordCount,
            "Characters"            => (string)$statistics->characterCount,
            "Display time"          => self::number($statistics->totalDisplayTime) . " s",
            "Span"                  => $statistics->span === null ? "-" : self::number($statistics->span) . " s",
            "Characters per second" => $range($statistics->charactersPerSecond),
            "Words per minute"      => $range($statistics->wordsPerMinute),
            "Characters per line"   => $range($statistics->charactersPerLine),
            "Gaps"                  => $range($statistics->gaps, " s"),
            "Most used words"       => implode(", ", $words),
        ];
        foreach ($subtitle->getAllMetadata() as $key => $value) {
            $rows["Metadata $key"] = $value;
        }

        $text = self::label($input) . "\n" . self::table(array_map(fn (string $name, string $value): array => ["$name:", $value],
                                                                   array_keys($rows), $rows), 2, 1);

        $data = $statistics->toArray();
        $this->emit($console, ($this->succeeded > 0 ? "\n" : "") . $text, [
            "file"       => $input,
            "format"     => $format->value,
            "metadata"   => (object)$subtitle->getAllMetadata(),
            "statistics" => $data,
            "imageCues"  => ["count" => count($imageCues), "withText" => $imageCuesWithText],
            "warnings"   => self::warningsJson($this->parseWarnings),
        ]);
    }
}
