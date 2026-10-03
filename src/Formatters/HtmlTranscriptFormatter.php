<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\HtmlTranscriptOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

class HtmlTranscriptFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = HtmlTranscriptOptions::class;


    /**
     * Writes the Podcasting 2.0 HTML transcript, a <cite>, <time> and <p> per paragraph. A speaker change or a gap of
     * HtmlTranscriptOptions::$paragraphGap seconds starts a new paragraph.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $paragraphGap = ($this->formatOptions($options) ?? new HtmlTranscriptOptions())->paragraphGap;

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
            [$hours, $minutes, $seconds] = Timecode::seconds(floor($paragraph["start"]));
            $time = $hours > 0 ? sprintf("%d:%02d:%02d", $hours, $minutes, $seconds) : sprintf("%d:%02d", $minutes, $seconds);
            $html .= "<time>$time</time>" . StringHelpers::UNIX_LINE_ENDING .
                     "<p>" . Markup::escapeText(implode(" ", $paragraph["bodies"])) . "</p>" . StringHelpers::UNIX_LINE_ENDING;
        }

        return $this->applyOutputOptions($html, $options);
    }
}
