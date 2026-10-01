<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class SbvFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = array_filter(
                array_map(fn (string $line): string => trim($this->toPlainText($line)), $cue->getLines()),
                fn (string $line): bool => $line !== ""
            );

            // An empty line ends an SBV cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $blocks[] = $this->formatTime($cue->getStart()) . "," . $this->formatTime($cue->getEnd()) .
                        StringHelpers::UNIX_LINE_ENDING .
                        implode(StringHelpers::UNIX_LINE_ENDING, $lines) .
                        StringHelpers::UNIX_LINE_ENDING;
        }

        return implode(StringHelpers::UNIX_LINE_ENDING, $blocks);
    }


    private function formatTime(float $seconds): string
    {
        $totalMillis = (int) round($seconds * 1000);

        $hours   = intdiv($totalMillis, 3600000);
        $minutes = intdiv($totalMillis, 60000) % 60;
        $secs    = intdiv($totalMillis, 1000) % 60;
        $millis  = $totalMillis % 1000;

        return sprintf("%d:%02d:%02d.%03d", $hours, $minutes, $secs, $millis);
    }


    private function toPlainText(string $line): string
    {
        return html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, "UTF-8");
    }
}
