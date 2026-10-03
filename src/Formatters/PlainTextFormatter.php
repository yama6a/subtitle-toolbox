<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\PlainTextOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

class PlainTextFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = PlainTextOptions::class;


    /**
     * Writes the text of the cues without markup and entities, in paragraphs that a gap of PlainTextOptions::$paragraphGap seconds starts.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $plainText = $this->formatOptions($options) ?? new PlainTextOptions();

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
            $paragraphs[count($paragraphs) - 1]["cues"][] = implode($plainText->joinLines ? " " : StringHelpers::UNIX_LINE_ENDING, $lines);
            $latestEnd = max($latestEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        $blocks = array_map(fn (array $paragraph): string =>
            ($plainText->withTimes ? sprintf("[%02d:%02d:%02d] ", ...Timecode::seconds(floor($paragraph["start"]))) : "") .
            implode($plainText->joinCues ? " " : StringHelpers::UNIX_LINE_ENDING, $paragraph["cues"]) .
            StringHelpers::UNIX_LINE_ENDING, $paragraphs);

        return $this->applyOutputOptions(implode(StringHelpers::UNIX_LINE_ENDING, $blocks), $options);
    }


    private function plainLine(string $line): string
    {
        return trim(preg_replace('/[ \t]+/', " ", Markup::plainText($line)));
    }
}
