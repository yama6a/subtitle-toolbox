<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\HtmlTranscriptWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class HtmlTranscriptFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = HtmlTranscriptWriteOptions::class;


    /**
     * Writes the Podcasting 2.0 HTML transcript, a <cite>, <time> and <p> per paragraph. A speaker change or a gap of
     * HtmlTranscriptWriteOptions::$paragraphGap seconds starts a new paragraph.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $paragraphGap = ($this->formatOptions($options) ?? new HtmlTranscriptWriteOptions())->paragraphGap;

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
                $html .= "<cite>" . Markup::escapeText($paragraph["speaker"]) . ":</cite>" . LineEnding::Lf->value;
            }
            $time = Timecode::shortClock($paragraph["start"]);
            $html .= "<time>$time</time>" . LineEnding::Lf->value .
                     "<p>" . Markup::escapeText(implode(" ", $paragraph["bodies"])) . "</p>" . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($html, $options);
    }
}
