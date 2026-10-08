<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class HtmlTranscriptParser extends SubtitleParser
{
    private const ELEMENT = '/<(cite|time|p)(?:\s[^>]*)?>(.*?)<\/\1\s*>/is';

    private const TIME = '/^(?:(\d+):)?(\d+):([0-5]?\d)(?:[.,](\d+))?$/';


    /**
     * Reads the Podcasting 2.0 HTML transcript, one cue per <time> with the <p> paragraphs up to the next <time>.
     */
    protected function read(string $content): Subtitle
    {
        $paragraphs = $this->paragraphs(StringHelpers::normalizeEOLs($content));

        $cues = [];
        foreach ($paragraphs as $index => $paragraph) {
            if ($paragraph["lines"] === []) {
                continue;
            }

            try {
                $cues[] = [$this->seconds($paragraph), $paragraph["speaker"] ?? "", $paragraph["lines"]];
            } catch (ParsingException $exception) {
                $this->fail($exception, $exception->getLineNumber(), $index, [$paragraph["time"][0] ?? $paragraph["lines"][0]]);
            }
        }

        $starts     = array_column($cues, 0);
        $parsedCues = [];
        foreach ($cues as $index => [$start, $speaker, $lines]) {
            if ($speaker !== "") {
                $lines[0] = Markup::voiceTag($speaker) . $lines[0];
            }

            $parsedCues[] = new SubtitleCue($start, $this->endAtNextStart($starts, $index), $lines);
        }

        return (new Subtitle())->addCues($parsedCues);
    }


    /**
     * Groups the <cite>, <time> and <p> elements into paragraphs. A <cite> or <time> after text starts a new paragraph.
     *
     * @return list<array{line: int, speaker: ?string, time: ?array{string, int}, lines: list<string>}>
     */
    private function paragraphs(string $content): array
    {
        preg_match_all(self::ELEMENT, $content, $elements, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $paragraphs = [];
        $line       = 1;
        $lineOffset = 0;
        foreach ($elements as [[, $offset], [$name], [$inner]]) {
            $line      += substr_count($content, "\n", $lineOffset, $offset - $lineOffset);
            $lineOffset = $offset;
            $name       = strtolower($name);
            $current    = $paragraphs === [] ? null : $paragraphs[count($paragraphs) - 1];
            if ($current === null
                || ($name === "cite" && ($current["speaker"] !== null || $current["lines"] !== []))
                || ($name === "time" && ($current["time"] !== null || $current["lines"] !== []))) {
                $paragraphs[] = ["line" => $line, "speaker" => null, "time" => null, "lines" => []];
            }

            $last = count($paragraphs) - 1;
            if ($name === "cite") {
                $paragraphs[$last]["speaker"] = trim(rtrim($this->text($inner), ":"));
            } elseif ($name === "time") {
                $paragraphs[$last]["time"] = [$this->text($inner), $line];
            } else {
                array_push($paragraphs[$last]["lines"], ...$this->htmlLines($inner));
            }
        }

        return $paragraphs;
    }


    private function seconds(array $paragraph): float
    {
        if ($paragraph["time"] === null) {
            throw new ParsingException("The paragraph has no <time>.", $paragraph["line"]);
        }

        [$time, $line] = $paragraph["time"];
        if (preg_match(self::TIME, $time, $parts) !== 1) {
            throw new ParsingException("The time \"$time\" is not valid.", $line);
        }

        return Timecode::roundToMilliseconds(Timecode::toSeconds((int) $parts[1], (int) $parts[2], (int) $parts[3], $parts[4] ?? ""));
    }


    /**
     * Returns the escaped text lines of the HTML of a <p>, split at each <br>.
     *
     * @return list<string>
     */
    private function htmlLines(string $html): array
    {
        $lines = preg_split('/<br\s*\/?>/i', $html);

        return array_values(array_filter(
            array_map(fn (string $line): string => Markup::escapeText($this->text($line)), $lines),
            fn (string $line): bool => $line !== ""
        ));
    }


    private function text(string $html): string
    {
        return trim(preg_replace('/[ \t\n\r]+/', " ", Markup::plainText($html)) ?? "");
    }
}
