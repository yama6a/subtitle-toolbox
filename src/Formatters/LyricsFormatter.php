<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class LyricsFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $output   = $this->formatIdTags($subtitle);
        $cues     = array_values($subtitle->getCues());
        $comments = $subtitle->getComments();

        foreach ($cues as $cueIndex => $cue) {
            $output .= $this->formatComments($comments, $cueIndex, $cueIndex);
            $output .= $this->formatCue($cue);
            $output .= StringHelpers::UNIX_LINE_ENDING;

            if ($cue->getFormatData(LyricsParser::FORMAT)["endLine"] ?? false) {
                $output .= $this->formatTimeToString($cue->getEnd()) . StringHelpers::UNIX_LINE_ENDING;
            }
        }
        $output .= $this->formatComments($comments, count($cues), PHP_INT_MAX);

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
    }


    private function formatIdTags(Subtitle $subtitle): string
    {
        $tags = [];
        foreach (LyricsParser::METADATA_TAGS as $tag => $metadataKey) {
            if ($subtitle->getMetadata($metadataKey) !== null) {
                $tags[$tag] = $subtitle->getMetadata($metadataKey);
            }
        }
        $tags += $subtitle->getFormatData(LyricsParser::FORMAT)["idTags"] ?? [];

        $output = "";
        foreach ($tags as $tag => $value) {
            $output .= "[" . $tag . ":" . $this->toSingleLine($value) . "]" . StringHelpers::UNIX_LINE_ENDING;
        }

        return $output;
    }


    /**
     * @param list<array{text: string, beforeCueIndex: int}> $comments
     */
    private function formatComments(array $comments, int $fromCueIndex, int $toCueIndex): string
    {
        $output = "";
        foreach ($comments as $comment) {
            if ($comment["beforeCueIndex"] >= $fromCueIndex && $comment["beforeCueIndex"] <= $toCueIndex) {
                $output .= "[#:" . $this->toSingleLine($comment["text"]) . "]" . StringHelpers::UNIX_LINE_ENDING;
            }
        }

        return $output;
    }


    private function formatCue(SubtitleCue $cue): string
    {
        $timestamp = $this->formatTimeToString($cue->getStart());

        $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, implode(" ", $cue->getLines()), -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines = "";
        foreach ($parts as $idx => $part) {
            $lines .= $idx % 2 === 1 ? $this->formatWordTimestamp($part) : Markup::decodeEntities(Markup::stripAllTags($part));
        }

        return $timestamp . " " . $lines;
    }


    private function formatWordTimestamp(string $coreTimestamp): string
    {
        [$hours, $minutes, $seconds] = explode(":", trim($coreTimestamp, "<>"));

        return "<" . trim($this->formatTimeToString($hours * 3600 + $minutes * 60 + (float) $seconds), "[]") . ">";
    }


    private function toSingleLine(string $text): string
    {
        return preg_replace("/\s*\n\s*/", " ", trim($text));
    }


    private function formatTimeToString(float $timeInSeconds): string
    {
        // round once on the total, so 1.996 s becomes [00:02.00] and not [00:01.100]
        $totalCentiseconds = (int) round($timeInSeconds * 100);
        $minute            = str_pad(intdiv($totalCentiseconds, 6000), 2, "0", STR_PAD_LEFT);
        $second            = str_pad(intdiv($totalCentiseconds, 100) % 60, 2, "0", STR_PAD_LEFT);
        $centiseconds      = str_pad($totalCentiseconds % 100, 2, "0", STR_PAD_LEFT);

        return "[" . $minute . ":" . $second . "." . $centiseconds . "]";
    }
}
