<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Options;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class PlainTextFormatter extends SubtitleFormatter
{
    public const OPTION_JOIN_LINES    = "joinLines";
    public const OPTION_JOIN_CUES     = "joinCues";
    public const OPTION_PARAGRAPH_GAP = "paragraphGap";
    public const OPTION_WITH_TIMES    = "withTimes";


    /**
     * Writes the text of the cues without markup and entities, in paragraphs that a gap of OPTION_PARAGRAPH_GAP seconds starts.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $joinLines    = Options::flag($options, self::OPTION_JOIN_LINES) ?? true;
        $joinCues     = Options::flag($options, self::OPTION_JOIN_CUES) ?? true;
        $paragraphGap = $options[self::OPTION_PARAGRAPH_GAP] ?? 2.0;
        $withTimes    = Options::flag($options, self::OPTION_WITH_TIMES) ?? false;
        if (!is_int($paragraphGap) && !is_float($paragraphGap)) {
            throw new InvalidArgumentException("The option " . self::OPTION_PARAGRAPH_GAP . " must be a number of seconds.");
        }

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

            if ($latestEnd === null || $cue->getStart() - $latestEnd >= $paragraphGap) {
                $paragraphs[] = ["start" => $cue->getStart(), "cues" => []];
            }
            $paragraphs[count($paragraphs) - 1]["cues"][] = implode($joinLines ? " " : StringHelpers::UNIX_LINE_ENDING, $lines);
            $latestEnd = max($latestEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        $blocks = array_map(fn (array $paragraph): string =>
            ($withTimes ? $this->formatTime($paragraph["start"]) . " " : "") .
            implode($joinCues ? " " : StringHelpers::UNIX_LINE_ENDING, $paragraph["cues"]) .
            StringHelpers::UNIX_LINE_ENDING, $paragraphs);

        return $this->applyOutputOptions(implode(StringHelpers::UNIX_LINE_ENDING, $blocks), $options);
    }


    private function plainLine(string $line): string
    {
        return trim(preg_replace('/[ \t]+/', " ", Markup::plainText($line)));
    }


    private function formatTime(float $seconds): string
    {
        $totalSeconds = (int) floor($seconds);

        return sprintf("[%02d:%02d:%02d]", intdiv($totalSeconds, 3600), intdiv($totalSeconds, 60) % 60, $totalSeconds % 60);
    }
}
