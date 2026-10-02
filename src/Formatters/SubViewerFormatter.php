<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class SubViewerFormatter extends SubtitleFormatter
{
    /** Formatter option that selects SubViewer 1 or 2. The default is 2. */
    public const OPTION_VERSION = "OPTION_VERSION";

    private const VERSION_1_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "BEGIN" => ""];
    private const VERSION_2_DEFAULT_HEADER = ["SOURCE" => "", "PRG" => "", "FILEPATH" => "", "DELAY" => "0", "CD TRACK" => "0", "COMMENT" => ""];


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $version = $options[self::OPTION_VERSION] ?? 2;
        if ($version !== 1 && $version !== 2) {
            throw new InvalidArgumentException("The SubViewer version must be 1 or 2!");
        }

        $output = $version === 1 ? $this->formatVersion1($subtitle) : $this->formatVersion2($subtitle);

        return $this->applyOutputOptions($output, $options);
    }


    private function formatVersion1(Subtitle $subtitle): string
    {
        $header = $this->headerTags($subtitle, self::VERSION_1_DEFAULT_HEADER);
        // The parser applies a SubViewer 1 delay to the times, so the formatter writes 0.
        if (array_key_exists("DELAY", $header)) {
            $header["DELAY"] = "0";
        }

        $output = "";
        foreach ($header as $tag => $value) {
            $output .= "[$tag]" . StringHelpers::UNIX_LINE_ENDING . ($value === "" ? "" : $value . StringHelpers::UNIX_LINE_ENDING);
        }
        $output .= SubViewerParser::START_SCRIPT . StringHelpers::UNIX_LINE_ENDING;

        foreach ($subtitle->getCues() as $cue) {
            $lines = Markup::plainLines($cue->getLines());
            // An empty line after a time ends a SubViewer 1 cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $output .= $this->formatVersion1Time($cue->getStart()) . StringHelpers::UNIX_LINE_ENDING .
                       implode("|", $lines) . StringHelpers::UNIX_LINE_ENDING .
                       $this->formatVersion1Time($cue->getEnd()) . StringHelpers::UNIX_LINE_ENDING .
                       StringHelpers::UNIX_LINE_ENDING;
        }

        return $output . "[end]" . StringHelpers::UNIX_LINE_ENDING . "******** END SCRIPT ********" . StringHelpers::UNIX_LINE_ENDING;
    }


    private function formatVersion2(Subtitle $subtitle): string
    {
        $output = "[INFORMATION]" . StringHelpers::UNIX_LINE_ENDING;
        foreach ($this->headerTags($subtitle, self::VERSION_2_DEFAULT_HEADER) as $tag => $value) {
            $output .= "[$tag]$value" . StringHelpers::UNIX_LINE_ENDING;
        }
        $output .= "[END INFORMATION]" . StringHelpers::UNIX_LINE_ENDING . "[SUBTITLE]" . StringHelpers::UNIX_LINE_ENDING;

        $style = $subtitle->getFormatData(SubViewerParser::FORMAT)["style"] ?? null;
        if ($style !== null) {
            $output .= $style . StringHelpers::UNIX_LINE_ENDING;
        }

        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = Markup::plainLines($cue->getLines());
            // An empty line after the timing line would leave the cue without text.
            if ($lines === []) {
                continue;
            }

            $blocks[] = $this->formatVersion2Time($cue->getStart()) . "," . $this->formatVersion2Time($cue->getEnd()) .
                        StringHelpers::UNIX_LINE_ENDING .
                        implode("[br]", $lines) . StringHelpers::UNIX_LINE_ENDING;
        }

        return $output . implode(StringHelpers::UNIX_LINE_ENDING, $blocks);
    }


    /**
     * @param array<string, string> $defaultHeader
     * @return array<string, string>
     */
    private function headerTags(Subtitle $subtitle, array $defaultHeader): array
    {
        $header = [];
        foreach (SubViewerParser::METADATA_TAGS as $tag => $metadataKey) {
            $header[$tag] = $subtitle->getMetadata($metadataKey) ?? "";
        }

        return $header + ($subtitle->getFormatData(SubViewerParser::FORMAT)["header"] ?? $defaultHeader);
    }


    private function formatVersion1Time(float $seconds): string
    {
        $totalSeconds = (int) round($seconds);

        return sprintf("[%02d:%02d:%02d]", intdiv($totalSeconds, 3600), intdiv($totalSeconds, 60) % 60, $totalSeconds % 60);
    }


    private function formatVersion2Time(float $seconds): string
    {
        $totalCentis = (int) round($seconds * 100);

        $hours   = intdiv($totalCentis, 360000);
        $minutes = intdiv($totalCentis, 6000) % 60;
        $secs    = intdiv($totalCentis, 100) % 60;
        $centis  = $totalCentis % 100;

        return sprintf("%02d:%02d:%02d.%02d", $hours, $minutes, $secs, $centis);
    }
}
