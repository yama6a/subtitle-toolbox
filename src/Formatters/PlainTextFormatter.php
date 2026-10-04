<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\PlainTextWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class PlainTextFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = PlainTextWriteOptions::class;


    /**
     * Writes the text of the cues without markup and entities, in paragraphs that a gap of PlainTextWriteOptions::$paragraphGap seconds starts.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $plainText = $this->formatOptions($options) ?? new PlainTextWriteOptions();

        $paragraphs = [];
        $latestEnd  = null;
        foreach ($subtitle->getCues() as $cue) {
            $lines = array_values(array_filter(
                array_map($this->plainLine(...), $cue->getLines()),
                fn (string $line): bool => $line !== ""
            ));
            if ($lines === []) {
                continue;
            }

            if ($latestEnd === null || $cue->getStart() - $latestEnd >= $plainText->paragraphGap) {
                $paragraphs[] = ["start" => $cue->getStart(), "cues" => []];
            }
            $paragraphs[count($paragraphs) - 1]["cues"][] = implode($plainText->joinLines ? " " : LineEnding::Lf->value, $lines);
            $latestEnd = max($latestEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        $blocks = array_map(fn (array $paragraph): string =>
            ($plainText->withTimes ? sprintf("[%02d:%02d:%02d] ", ...Timecode::seconds(floor($paragraph["start"]))) : "") .
            implode($plainText->joinCues ? " " : LineEnding::Lf->value, $paragraph["cues"]) .
            LineEnding::Lf->value, $paragraphs);

        return $this->applyOutputOptions(implode(LineEnding::Lf->value, $blocks), $options);
    }


    private function plainLine(string $line): string
    {
        return trim(preg_replace('/[ \t]+/', " ", Markup::plainText($line)));
    }
}
