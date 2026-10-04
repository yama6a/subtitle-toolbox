<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class LyricsFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $output   = $this->formatIdTags($subtitle);
        $cues     = array_values($subtitle->getCues());
        $comments = $subtitle->getComments();

        foreach ($cues as $cueIndex => $cue) {
            $output .= $this->formatComments($comments, $cueIndex, $cueIndex);
            $output .= $this->formatCue($cue);
            $output .= StringHelpers::UNIX_LINE_ENDING;

            if ($cue->getFormatData(LyricsParser::FORMAT)["endLine"] ?? false) {
                $output .= $this->stamp($cue->getEnd()) . StringHelpers::UNIX_LINE_ENDING;
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
            $output .= "[" . $tag . ":" . Markup::toSingleLine($value) . "]" . StringHelpers::UNIX_LINE_ENDING;
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
                $output .= "[#:" . Markup::toSingleLine($comment["text"]) . "]" . StringHelpers::UNIX_LINE_ENDING;
            }
        }

        return $output;
    }


    private function formatCue(SubtitleCue $cue): string
    {
        $timestamp = $this->stamp($cue->getStart());

        $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, implode(" ", $cue->getLines()), -1, PREG_SPLIT_DELIM_CAPTURE);
        $lines = "";
        foreach ($parts as $idx => $part) {
            $lines .= $idx % 2 === 1 ? $this->wordStamp($part) : Markup::plainText($part);
        }

        return $timestamp . " " . $lines;
    }


    private function wordStamp(string $coreTag): string
    {
        return "<" . trim($this->stamp(Markup::wordTimestampSeconds($coreTag)), "[]") . ">";
    }


    private function stamp(float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds, $centiseconds] = Timecode::centiseconds($seconds);

        return sprintf("[%02d:%02d.%02d]", 60 * $hours + $minutes, $wholeSeconds, $centiseconds);
    }
}
