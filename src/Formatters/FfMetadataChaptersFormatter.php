<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class FfMetadataChaptersFormatter extends SubtitleFormatter
{
    private const DEFAULT_TIME_BASE = "1/1000";


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $stored = $subtitle->getFormatData(FfMetadataChaptersParser::FORMAT_DATA_KEY);
        $output = ";FFMETADATA1\n" . $this->tags($this->globalTags($subtitle, $stored["tags"] ?? []));
        foreach ($stored["streams"] ?? [] as $streamTags) {
            $output .= "[STREAM]\n" . $this->tags($streamTags);
        }

        foreach ($subtitle->getCues() as $cue) {
            $data     = $cue->getFormatData(FfMetadataChaptersParser::FORMAT_DATA_KEY);
            $timeBase = $data["timeBase"] ?? self::DEFAULT_TIME_BASE;
            [$numerator, $denominator] = array_map("intval", explode("/", $timeBase));
            $title    = implode("\n", Markup::plainLines($cue->getLines()));

            $output .= "[CHAPTER]\nTIMEBASE=$timeBase\n" .
                       "START=" . $this->ticks($cue->getStart(), $numerator, $denominator) . "\n" .
                       "END=" . $this->ticks($cue->getEnd(), $numerator, $denominator) . "\n" .
                       $this->tags(($title === "" ? [] : ["title" => $title]) + ($data["tags"] ?? []));
        }

        return $this->applyOutputOptions($output, $options);
    }


    // The stored tags keep their order, so an unchanged file comes out byte for byte.
    private function globalTags(Subtitle $subtitle, array $storedTags): array
    {
        $tags = [];
        foreach ($storedTags + array_fill_keys(FfMetadataChaptersParser::METADATA_KEYS, null) as $key => $value) {
            $tags[$key] = in_array($key, FfMetadataChaptersParser::METADATA_KEYS, true) ? $subtitle->getMetadata($key) : $value;
        }

        return array_filter($tags, fn (?string $value): bool => $value !== null);
    }


    private function tags(array $tags): string
    {
        $output = "";
        foreach ($tags as $key => $value) {
            $output .= $this->escape((string) $key) . "=" . $this->escape($value) . "\n";
        }

        return $output;
    }


    private function escape(string $text): string
    {
        return preg_replace('/[#;=\\\\\n]/', '\\\\$0', $text);
    }


    private function ticks(float $seconds, int $numerator, int $denominator): int
    {
        return (int) round($seconds * $denominator / $numerator);
    }
}
