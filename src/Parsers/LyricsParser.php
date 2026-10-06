<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class LyricsParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Lyrics->value;

    /**
     * Maps LRC ID tags to the shared metadata keys of Subtitle.
     *
     * @internal
     */
    public const METADATA_TAGS = [
        "ti" => Subtitle::METADATA_TITLE,
        "ar" => Subtitle::METADATA_ARTIST,
        "al" => Subtitle::METADATA_ALBUM,
        "au" => Subtitle::METADATA_AUTHOR,
    ];

    private const TIMESTAMP_PATTERN    = "(\d{2,3}):([0-5]\d)(?:\.(\d{2,3}))?";
    private const TIMESTAMP_LINE_REGEX = "/^((?:\[\d{2,3}:[0-5]\d(?:\.\d{2,3})?\])+)(.*)$/";
    private const ID_TAG_REGEX         = "/^\[([A-Za-z][A-Za-z0-9_]*|#):(.*)\]$/";
    private const OFFSET_REGEX         = "/^[+-]?\d+$/";


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle    = StringHelpers::normalizeEOLs($rawSubtitle);
        if ($this->lenient) {
            $this->warnBrokenTimeTags(explode(LineEnding::Lf->value, $rawSubtitle));
        }
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::removeEmptyLines($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);

        $lines      = explode(LineEnding::Lf->value, $rawSubtitle);
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $offset     = $this->findOffset($lines);
        $idTags     = [];
        $timeline   = [];

        foreach ($lines as $currentLine) {
            if (preg_match(self::TIMESTAMP_LINE_REGEX, $currentLine, $matches)) {
                $text = StringHelpers::cleanString($matches[2]);
                $text = $this->convertWordTimestamps($text, $offset);

                preg_match_all("/\[" . self::TIMESTAMP_PATTERN . "\]/", $matches[1], $timestamps, PREG_SET_ORDER);
                foreach ($timestamps as $timestamp) {
                    $start = $this->toSeconds($timestamp, $offset);
                    $cue   = $text === "" ? null : new SubtitleCue($start, $start, $text);
                    if ($cue !== null) {
                        $parsedCues[] = $cue;
                    }
                    $timeline[] = ["time" => $start, "cue" => $cue];
                }
                continue;
            }

            $this->addIdTag($subtitle, $idTags, $currentLine, count($parsedCues));
        }

        if ($idTags !== []) {
            $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["idTags" => $idTags]);
        }

        $this->assignEndTimes($timeline);
        $subtitle->addCues($parsedCues);

        return $subtitle;
    }


    /**
     * @param list<string> $lines
     */
    private function warnBrokenTimeTags(array $lines): void
    {
        $blockIndex = 0;
        foreach ($lines as $lineIndex => $line) {
            $line = trim(StringHelpers::normalizeSpaces($line));
            if ($line === "") {
                continue;
            }

            if (preg_match("/^\[\d/", $line) && !preg_match(self::TIMESTAMP_LINE_REGEX, $line)) {
                $lineNumber = $lineIndex + 1;
                $this->warn("Line $lineNumber has a time tag that could not be parsed: $line", $lineNumber, $blockIndex, [$line], ParseWarningAction::Skipped);
            }
            $blockIndex++;
        }
    }


    /**
     * @param list<string> $lines
     */
    private function findOffset(array $lines): float
    {
        foreach ($lines as $line) {
            if (preg_match(self::ID_TAG_REGEX, $line, $matches)
                && strtolower($matches[1]) === "offset"
                && preg_match(self::OFFSET_REGEX, trim($matches[2]))
            ) {
                return (int) trim($matches[2]) / 1000;
            }
        }

        return 0;
    }


    /**
     * @param array<string, string> $idTags
     */
    private function addIdTag(Subtitle $subtitle, array &$idTags, string $line, int $cueCount): void
    {
        if (!preg_match(self::ID_TAG_REGEX, $line, $matches)) {
            return;
        }

        $tag   = strtolower($matches[1]);
        $value = trim($matches[2]);

        if ($tag === "#") {
            $subtitle->addComment($value, $cueCount);
        } elseif (array_key_exists($tag, self::METADATA_TAGS)) {
            $subtitle->setMetadata(self::METADATA_TAGS[$tag], $value);
        } elseif ($tag !== "offset" || !preg_match(self::OFFSET_REGEX, $value)) {
            $idTags[$tag] = $value;
        }
    }


    /**
     * @param list<array{time: float, cue: ?SubtitleCue}> $timeline
     */
    private function assignEndTimes(array $timeline): void
    {
        usort($timeline, fn (array $entry1, array $entry2): int => $entry1["time"] <=> $entry2["time"]);

        foreach ($timeline as $idx => $entry) {
            if ($entry["cue"] === null) {
                continue;
            }

            $next = $timeline[$idx + 1] ?? null;
            if ($next === null) {
                $entry["cue"]->setEnd($entry["time"] + $this->options->lastCueDuration);
                continue;
            }

            $entry["cue"]->setEnd($next["time"]);
            if ($next["cue"] === null) {
                $entry["cue"]->setFormatData(self::FORMAT_DATA_KEY, ["endLine" => true]);
            }
        }
    }


    private function convertWordTimestamps(string $text, float $offset): string
    {
        return preg_replace_callback(
            "/<" . self::TIMESTAMP_PATTERN . ">|[^<]+|</",
            fn (array $matches): string => isset($matches[1])
                ? "<" . Markup::coreTimestamp($this->toSeconds($matches, $offset)) . ">"
                : Markup::escapeText($matches[0]),
            $text
        );
    }


    /**
     * @param array<int, string> $matches minutes, seconds and an optional fraction in groups 1 to 3
     */
    private function toSeconds(array $matches, float $offset): float
    {
        $fraction = $matches[3] ?? "";
        $seconds  = match (strlen($fraction)) {
            0       => $matches[1] * 60 + $matches[2],
            2       => $matches[1] * 60 + $matches[2] + round($fraction / 100, 2),
            default => $matches[1] * 60 + $matches[2] + round($fraction / 1000, 3),
        };

        return $offset === 0.0 ? (float) $seconds : max(0.0, round($seconds - $offset, 3));
    }
}
