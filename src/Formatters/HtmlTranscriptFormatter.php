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
     * Writes the Podcasting 2.0 HTML transcript, a <cite>, <time> and <p> per paragraph.
     * A speaker change or a gap of HtmlTranscriptWriteOptions::$paragraphGap seconds starts a new paragraph.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $items = array_map(
            fn (array $segment): array => [$segment["startTime"], $segment["endTime"], $segment],
            (new PodcastTranscriptFormatter())->segments($subtitle)
        );
        $paragraphs = Paragraphs::byGap(
            $items,
            $this->formatOptions($options)->paragraphGap,
            fn (array $previous, array $segment): bool => ($previous["speaker"] ?? null) !== ($segment["speaker"] ?? null)
        );

        $html = "";
        foreach ($paragraphs as $paragraph) {
            $speaker = $paragraph["values"][0]["speaker"] ?? null;
            if ($speaker !== null) {
                $html .= "<cite>" . Markup::escapeText($speaker) . ":</cite>" . LineEnding::Lf->value;
            }
            $time = Timecode::shortClock($paragraph["start"]);
            $html .= "<time>$time</time>" . LineEnding::Lf->value .
                     "<p>" . Markup::escapeText(implode(" ", array_column($paragraph["values"], "body"))) . "</p>" . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($html, $options);
    }
}
