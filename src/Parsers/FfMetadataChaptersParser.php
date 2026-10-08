<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * The section reading follows libavformat/ffmetadec.c.
 *
 * @see https://ffmpeg.org/ffmpeg-formats.html#Metadata-1
 */
final class FfMetadataChaptersParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = ChapterReadOptions::class;
    public const FORMAT_DATA_KEY = Format::FfMetadataChapters->value;

    /** @internal */
    public const METADATA_KEYS = [
        Subtitle::METADATA_TITLE, Subtitle::METADATA_AUTHOR, Subtitle::METADATA_ARTIST,
        Subtitle::METADATA_ALBUM, Subtitle::METADATA_LANGUAGE,
    ];

    // ffmetadec.c reads START and END without a TIMEBASE line in nanoseconds.
    private const DEFAULT_TIME_BASE = "1/1000000000";


    protected function read(string $rawSubtitle): Subtitle
    {
        $content = StringHelpers::normalizeEOLs($rawSubtitle);
        if (!str_starts_with($content, ";FFMETADATA")) {
            throw new ParsingException("The content does not start with the ;FFMETADATA header.", 1);
        }

        $lines    = $this->logicalLines($content);
        $global   = [];
        $streams  = [];
        $chapters = [];
        $tags     = &$global;
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i][1];
            if ($line === "[STREAM]") {
                $streams[] = [];
                $tags      = &$streams[array_key_last($streams)];
            } elseif ($line === "[CHAPTER]") {
                $previousEnd = $chapters === [] ? 0.0 : $chapters[array_key_last($chapters)]["end"] ?? 0.0;
                $chapters[]  = $this->readChapterTimes($lines, $i, $previousEnd);
                $tags        = &$chapters[array_key_last($chapters)]["tags"];
            } else {
                $tags = $this->readTag($line, $tags);
            }
        }
        unset($tags);

        return $this->buildSubtitle($global, $streams, $chapters);
    }


    /**
     * @return list<array{int, string}> the 1-based number of the first physical line, and the line joined with the
     *                                  lines that an escaped line break continues it with
     */
    private function logicalLines(string $content): array
    {
        $lines    = [];
        $pending  = null;
        $physical = $this->lines($content);
        foreach ($physical as $index => $line) {
            $pending = $pending === null ? [$index + 1, $line] : [$pending[0], $pending[1] . "\n" . $line];
            if ($index < count($physical) - 1 && (strlen($pending[1]) - strlen(rtrim($pending[1], "\\"))) % 2 === 1) {
                continue;
            }
            if ($pending[1] !== "" && $pending[1][0] !== ";" && $pending[1][0] !== "#") {
                $lines[] = $pending;
            }
            $pending = null;
        }

        return $lines;
    }


    // ffmetadec.c reads TIMEBASE, START and END only in this order, right after the [CHAPTER] line.
    private function readChapterTimes(array $lines, int &$i, float $previousEnd): array
    {
        $timeBase = self::DEFAULT_TIME_BASE;
        $next     = $lines[$i + 1] ?? [0, ""];
        if (preg_match('/^TIMEBASE=(\d+)\/(\d+)/', $next[1], $matches) === 1) {
            if ((int) $matches[1] === 0 || (int) $matches[2] === 0) {
                throw new ParsingException("The chapter time base $matches[1]/$matches[2] is not valid.", $next[0]);
            }
            $timeBase = "$matches[1]/$matches[2]";
            $next     = $lines[++$i + 1] ?? [0, ""];
        }
        [$numerator, $denominator] = array_map("intval", explode("/", $timeBase));

        $start = $previousEnd;
        if (preg_match('/^START=(-?\d+)/', $next[1], $matches) === 1) {
            $start = (int) $matches[1] * $numerator / $denominator;
            $next  = $lines[++$i + 1] ?? [0, ""];
        }

        $end = null;
        if (preg_match('/^END=(-?\d+)/', $next[1], $matches) === 1) {
            $end = (int) $matches[1] * $numerator / $denominator;
            $i++;
        }

        return ["start" => $start, "end" => $end, "timeBase" => $timeBase, "tags" => []];
    }


    // ffmetadec.c ignores a line without an unescaped "=".
    private function readTag(string $line, array $tags): array
    {
        if (preg_match('/^((?:[^\\\\=]|\\\\.)*)=(.*)$/s', $line, $matches) === 1) {
            $tags[$this->unescape($matches[1])] = $this->unescape($matches[2]);
        }

        return $tags;
    }


    private function unescape(string $text): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $text);
    }


    private function buildSubtitle(array $global, array $streams, array $chapters): Subtitle
    {
        $subtitle = new Subtitle();
        foreach (array_intersect_key($global, array_flip(self::METADATA_KEYS)) as $key => $value) {
            $subtitle->setMetadata($key, $value);
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["tags" => $global, "streams" => $streams]);

        $parsedCues = [];
        foreach ($chapters as $chapter) {
            $cue = new SubtitleCue($chapter["start"], $chapter["start"], Markup::escapeText($chapter["tags"]["title"] ?? ""));
            $cue->setFormatData(self::FORMAT_DATA_KEY, [
                "timeBase" => $chapter["timeBase"],
                "tags"     => array_diff_key($chapter["tags"], ["title" => true]),
            ]);
            $parsedCues[] = $cue;
        }

        return $subtitle->addCues($this->endChapters($parsedCues, array_map(fn (array $chapter): ?float => $chapter["end"], $chapters)));
    }
}
