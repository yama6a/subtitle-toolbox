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
     * Writes the text of the cues without markup and entities.
     * A gap of PlainTextWriteOptions::$paragraphGap seconds starts a new paragraph.
     */
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $formatOptions = $this->formatOptions($options);

        $items = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = array_values(array_filter(
                array_map($this->plainLine(...), array_map(Markup::rubyAsText(...), $cue->getLines())),
                fn (string $line): bool => $line !== ""
            ));
            if ($lines !== []) {
                $items[] = [$cue->getStart(), $cue->getEnd(), implode($formatOptions->joinLines ? " " : LineEnding::Lf->value, $lines)];
            }
        }

        $blocks = array_map(fn (array $paragraph): string =>
            ($formatOptions->withTimes ? sprintf("[%02d:%02d:%02d] ", ...Timecode::seconds(floor($paragraph["start"]))) : "") .
            implode($formatOptions->joinCues ? " " : LineEnding::Lf->value, $paragraph["values"]) .
            LineEnding::Lf->value, Paragraphs::byGap($items, $formatOptions->paragraphGap));

        return $this->applyOutputOptions(implode(LineEnding::Lf->value, $blocks), $options);
    }


    private function plainLine(string $line): string
    {
        return trim(preg_replace('/[ \t]+/', " ", Markup::plainText($line)));
    }
}
