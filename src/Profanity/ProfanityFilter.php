<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class ProfanityFilter
{
    private const WORD_CHARACTER = '[\p{L}\p{M}\p{N}_]';


    /**
     * Masks the words of the options in the cue text and reports the time ranges of the matches, sorted and joined.
     */
    public static function apply(Subtitle $subtitle, ProfanityOptions $options): ProfanityReport
    {
        $pattern     = self::pattern($options->words);
        $ranges      = [];
        $removedCues = new \SplObjectStorage();

        foreach ($subtitle->getCues() as $index => $cue) {
            $lines = array_values($cue->getLines());
            if ($lines === []) {
                continue;
            }

            $changed = self::filterCue($cue, $lines, $pattern, $options, $ranges);
            if ($changed === null) {
                continue;
            }

            $hadText = Markup::hasVisibleText($lines);
            $cue->setLinesByArray($changed);
            if ($hadText && !Markup::hasVisibleText($cue->getLines())) {
                $removedCues[$cue] = true;
            }
        }

        if ($removedCues->count() > 0) {
            $subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => isset($removedCues[$cue]));
        }

        return new ProfanityReport(self::join($ranges, $options->padding));
    }


    /**
     * @param list<string> $words
     */
    private static function pattern(array $words): string
    {
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = array_map(
            fn (string $word): string => str_ends_with($word, "*")
                ? preg_quote(substr($word, 0, -1), "/") . self::WORD_CHARACTER . "*"
                : preg_quote($word, "/"),
            $words
        );

        return "/(?<!" . self::WORD_CHARACTER . ")(?:" . implode("|", $alternatives) . ")(?!" . self::WORD_CHARACTER . ")/iu";
    }


    /**
     * Masks the text runs of the cue and adds one range for each run with a match. Returns null when no text changed.
     *
     * @param list<string> $lines
     * @param list<array{float, float}> $ranges
     * @return list<string>|null
     */
    private static function filterCue(SubtitleCue $cue, array $lines, string $pattern, ProfanityOptions $options,
                                      array &$ranges): ?array
    {
        $tokens = array_map(fn (string $line): array => Markup::splitTags($line), $lines);
        $starts = [];
        $time   = null;
        foreach ($tokens as $lineIndex => $lineTokens) {
            foreach ($lineTokens as $tokenIndex => $token) {
                $time = Markup::wordTimestampSeconds($token) ?? $time;
                $starts[$lineIndex][$tokenIndex] = $time;
            }
        }
        $ends = [];
        $time = null;
        foreach (array_reverse($tokens, true) as $lineIndex => $lineTokens) {
            foreach (array_reverse($lineTokens, true) as $tokenIndex => $token) {
                $ends[$lineIndex][$tokenIndex] = $time;
                $time = Markup::wordTimestampSeconds($token) ?? $time;
            }
        }

        $changed = false;
        foreach ($tokens as $lineIndex => $lineTokens) {
            foreach ($lineTokens as $tokenIndex => $token) {
                if ($tokenIndex % 2 === 1 || $token === "") {
                    continue;
                }

                $text   = Markup::unescapeText($token);
                $masked = preg_replace_callback($pattern, fn (array $match): string => self::mask($match[0], $options->mask), $text, -1, $count);
                if ($masked === null || $count === 0) {
                    continue;
                }

                $ranges[] = [$starts[$lineIndex][$tokenIndex] ?? $cue->getStart(), $ends[$lineIndex][$tokenIndex] ?? $cue->getEnd()];
                if ($masked !== $text) {
                    $tokens[$lineIndex][$tokenIndex] = Markup::escapeTextLike($masked, $token);
                    $changed = true;
                }
            }
        }

        return $changed ? array_map(fn (array $lineTokens): string => implode("", $lineTokens), $tokens) : null;
    }


    private static function mask(string $word, string|\Closure $mask): string
    {
        if ($mask instanceof \Closure) {
            return $mask($word);
        }

        preg_match_all('/\X/u', $word, $characters);
        $characters = $characters[0];

        return match ($mask) {
            ProfanityOptions::MASK_STARS        => str_repeat("*", count($characters)),
            ProfanityOptions::MASK_FIRST_LETTER => $characters[0] . str_repeat("*", count($characters) - 1),
            ProfanityOptions::MASK_REMOVE       => "",
            ProfanityOptions::MASK_NONE         => $word,
        };
    }


    /**
     * @param list<array{float, float}> $ranges
     * @return list<MuteRange>
     */
    private static function join(array $ranges, float $padding): array
    {
        $ranges = array_map(fn (array $range): array => [round(max(0, $range[0] - $padding), 3), round($range[1] + $padding, 3)], $ranges);
        sort($ranges);

        $joined = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($joined) - 1;
            if ($last >= 0 && $start <= $joined[$last][1]) {
                $joined[$last][1] = max($joined[$last][1], $end);
            } else {
                $joined[] = [$start, $end];
            }
        }

        return array_map(fn (array $range): MuteRange => new MuteRange($range[0], $range[1]), $joined);
    }
}
