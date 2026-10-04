<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class Mpl2Formatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            $output .= "[" . (int) round($cue->getStart() * 10) . "][" . (int) round($cue->getEnd() * 10) . "]" .
                       implode("|", self::linesWithItalics($cue->getLines())) .
                       StringHelpers::UNIX_LINE_ENDING;
        }

        return $this->applyOutputOptions($output, $options);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function linesWithItalics(array $lines): array
    {
        $italic = false;
        $result = [];
        foreach ($lines as $line) {
            $allItalic = true;
            foreach (preg_split('/(<\/?i>)/i', $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if (preg_match('/^<(\/?)i>$/i', $part, $tag)) {
                    $italic = $tag[1] === "";
                } elseif (trim(Markup::stripAllTags($part)) !== "" && !$italic) {
                    $allItalic = false;
                }
            }

            $text = trim(Markup::plainText($line));
            if ($text !== "") {
                $result[] = ($allItalic ? "/" : "") . $text;
            }
        }

        return $result;
    }
}
