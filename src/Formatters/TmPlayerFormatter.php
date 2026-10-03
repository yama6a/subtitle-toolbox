<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class TmPlayerFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $cues = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = Markup::plainLines($cue->getLines());
            // A line without text would end the cue before it.
            if ($lines !== []) {
                $cues[] = [$cue, $lines];
            }
        }

        $output = "";
        foreach ($cues as $index => [$cue, $lines]) {
            $start   = (int) round($cue->getStart());
            $output .= self::timestamp($start) . implode("|", $lines) . StringHelpers::UNIX_LINE_ENDING;

            if (!isset($cues[$index + 1])) {
                continue;
            }
            // TMPlayer has no end times. An entry without text hides the cue before the next one starts.
            $end = max((int) round($cue->getEnd()), $start + 1);
            if ($end < (int) round($cues[$index + 1][0]->getStart())) {
                $output .= self::timestamp($end) . StringHelpers::UNIX_LINE_ENDING;
            }
        }

        return $this->applyOutputOptions($output, $options);
    }


    private static function timestamp(int $seconds): string
    {
        return sprintf("%02d:%02d:%02d:", intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }
}
