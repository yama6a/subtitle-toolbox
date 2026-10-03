<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class HtmlTranscriptFormatter extends SubtitleFormatter
{
    public const OPTION_PARAGRAPH_GAP = "paragraphGap";


    /**
     * Writes the Podcasting 2.0 HTML transcript, a <cite>, <time> and <p> per paragraph. A speaker change or a gap of
     * OPTION_PARAGRAPH_GAP seconds starts a new paragraph.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $paragraphGap = $options[self::OPTION_PARAGRAPH_GAP] ?? 2.0;
        if (!is_int($paragraphGap) && !is_float($paragraphGap)) {
            throw new InvalidArgumentException("The option " . self::OPTION_PARAGRAPH_GAP . " must be a number of seconds.");
        }

        $paragraphs = [];
        $latestEnd  = null;
        foreach ((new PodcastTranscriptFormatter())->segments($subtitle) as $segment) {
            $speaker = $segment["speaker"] ?? null;
            $last    = $paragraphs === [] ? null : $paragraphs[count($paragraphs) - 1];
            if ($last === null || $last["speaker"] !== $speaker || $segment["startTime"] - $latestEnd >= $paragraphGap) {
                $paragraphs[] = ["speaker" => $speaker, "start" => $segment["startTime"], "bodies" => []];
            }
            $paragraphs[count($paragraphs) - 1]["bodies"][] = $segment["body"];
            $latestEnd = max($latestEnd ?? $segment["endTime"], $segment["endTime"]);
        }

        $html = "";
        foreach ($paragraphs as $paragraph) {
            if ($paragraph["speaker"] !== null) {
                $html .= "<cite>" . Markup::escapeText($paragraph["speaker"]) . ":</cite>" . StringHelpers::UNIX_LINE_ENDING;
            }
            $html .= "<time>" . $this->formatTime($paragraph["start"]) . "</time>" . StringHelpers::UNIX_LINE_ENDING .
                     "<p>" . Markup::escapeText(implode(" ", $paragraph["bodies"])) . "</p>" . StringHelpers::UNIX_LINE_ENDING;
        }

        return $this->applyOutputOptions($html, $options);
    }


    private function formatTime(float $seconds): string
    {
        $totalSeconds = (int)floor($seconds);
        $hours        = intdiv($totalSeconds, 3600);
        $minutes      = intdiv($totalSeconds, 60) % 60;

        return $hours > 0
            ? sprintf("%d:%02d:%02d", $hours, $minutes, $totalSeconds % 60)
            : sprintf("%d:%02d", $minutes, $totalSeconds % 60);
    }
}
