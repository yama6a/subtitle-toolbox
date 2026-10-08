<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\DialogueDash;
use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * @internal
 */
final class TextChecks
{
    /**
     * Returns one result per text rule that the cue breaks.
     *
     * @return list<ValidationViolation>
     */
    public static function check(int $cueIndex, SubtitleCue $cue, float $duration, ValidationRules $rules): array
    {
        $lines   = array_map(fn (string $line): string => Markup::plainText($line), $cue->getLines());
        $visible = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ""));
        $counts  = [];

        if ($rules->noDoubleSpaces) {
            $counts[] = [ValidationRule::NoDoubleSpaces, array_sum(array_map(
                fn (string $line): int => self::count('/(?<=\S)\h{2,}(?=\S)/u', '/(?<=\S)[ \t]{2,}(?=\S)/', $line),
                $visible
            )), null];
        }

        if ($rules->noLeadingOrTrailingSpaces) {
            $counts[] = [ValidationRule::NoLeadingOrTrailingSpaces, count(array_filter(
                $visible,
                fn (string $line): bool => self::count('/^\h|\h$/u', '/^[ \t]|[ \t]$/', $line) > 0
            )), null];
        }

        if ($rules->noUnbalancedTags) {
            $counts[] = [ValidationRule::NoUnbalancedTags, self::unbalancedTags(implode("\n", $cue->getLines())), null];
        }

        if ($rules->dialogueDashStyle !== null) {
            $style = '/^' . preg_quote($rules->dialogueDashStyle->value, "/") . '(?=\S)/u';
            $counts[] = [ValidationRule::DialogueDashStyle, count(array_filter(
                $visible,
                fn (string $line): bool => self::startsWithDialogueDash($line) && preg_match($style, ltrim($line)) !== 1
            )), null];
        }

        if ($rules->maxSpeakersPerCue !== null) {
            $speakers = self::speakers($cue->getLines(), $visible);
            if ($speakers > $rules->maxSpeakersPerCue) {
                $counts[] = [ValidationRule::MaxSpeakersPerCue, $speakers, $rules->maxSpeakersPerCue];
            }
        }

        $words = count(Markup::words(implode("\n", $lines)));
        if ($rules->maxWordsPerMinute !== null && $words > 0) {
            $wordsPerMinute = $duration > 0 ? $words / $duration * 60 : INF;
            if ($wordsPerMinute > $rules->maxWordsPerMinute) {
                $counts[] = [ValidationRule::MaxWordsPerMinute, $wordsPerMinute, $rules->maxWordsPerMinute];
            }
        }

        // Cue times have millisecond precision, so compare the duration with the needed time in milliseconds.
        if ($rules->minSecondsPerWord !== null && $words > 0 && $duration < Timecode::roundToMilliseconds($rules->minSecondsPerWord * $words)) {
            $counts[] = [ValidationRule::MinSecondsPerWord, $duration / $words, $rules->minSecondsPerWord];
        }

        if ($rules->allowedCharacters !== null) {
            $counts[] = [ValidationRule::AllowedCharacters, array_sum(array_map(
                fn (string $line): int => self::disallowedCharacters($line, $rules->allowedCharacters),
                $visible
            )), null];
        }

        if ($rules->noAllCapsLines) {
            $counts[] = [ValidationRule::NoAllCapsLines, count(array_filter(
                $visible,
                fn (string $line): bool => self::isAllCaps($line)
            )), null];
        }

        $results = [];
        foreach ($counts as [$rule, $value, $limit]) {
            // A count rule gives the int 0 for a cue without problems. A limit rule is only set when the cue breaks it.
            if ($value !== 0) {
                $results[] = new ValidationViolation($cueIndex, $rule, $value, $limit);
            }
        }

        return $results;
    }


    /**
     * Tells if $characters is a regular expression character class such as "[A-Za-z0-9 .,!?]".
     */
    public static function isCharacterClass(string $characters): bool
    {
        return strlen($characters) >= 2 && $characters[0] === "[" && str_ends_with($characters, "]");
    }


    /**
     * Returns the pattern without modifiers that matches one character of the class $characters.
     */
    public static function characterClassPattern(string $characters): string
    {
        $escaped = preg_replace_callback(
            '/\\\\.|\//s',
            fn (array $match): string => $match[0] === "/" ? "\\/" : $match[0],
            $characters
        );

        return '/^(?:' . $escaped . ')$/';
    }


    private static function startsWithDialogueDash(string $line): bool
    {
        return preg_match(DialogueDash::REGEX, ltrim($line)) === 1;
    }


    /**
     * @param list<string> $markupLines
     * @param list<string> $visible
     */
    private static function speakers(array $markupLines, array $visible): int
    {
        $dashLines = count(array_filter($visible, fn (string $line): bool => self::startsWithDialogueDash($line)));

        preg_match_all('/' . Markup::VOICE_TAG . '/', implode("\n", $markupLines), $matches);
        $names = array_unique(array_map("trim", $matches[2]));

        return max($dashLines, count($names), $visible === [] ? 0 : 1);
    }


    /**
     * Counts closing tags without an opening tag and opening tags without a closing tag. An open <v> needs no </v>.
     */
    private static function unbalancedTags(string $text): int
    {
        $tags = Markup::unbalancedTags([$text], Markup::CORE_TAGS, true);

        return count($tags["stray"]) + count(array_filter([...$tags["inner"], ...$tags["open"]], fn (string $tag): bool => $tag !== "v"));
    }


    private static function disallowedCharacters(string $line, string $allowed): int
    {
        $isUtf8     = preg_match('//u', $line) === 1;
        $characters = Markup::characters($line);

        if (self::isCharacterClass($allowed)) {
            $pattern = self::characterClassPattern($allowed) . ($isUtf8 ? "u" : "");

            return count(array_filter($characters, fn (string $character): bool =>
                $character !== " " && preg_match($pattern, $character) !== 1));
        }

        $set = array_flip(Markup::characters($allowed));

        return count(array_filter($characters, fn (string $character): bool =>
            $character !== " " && !isset($set[$character])));
    }


    /**
     * Tells if a line has two or more upper case letters and no lower case letter. Text in brackets does not count.
     */
    private static function isAllCaps(string $line): bool
    {
        $line = preg_replace('/\[[^\]]*\]|\([^)]*\)/', "", $line);

        return self::count('/\p{Lu}/u', '/[A-Z]/', $line) >= 2 && self::count('/\p{Ll}/u', '/[a-z]/', $line) === 0;
    }


    /**
     * Counts the matches of $utf8Pattern, or of $bytePattern for invalid UTF-8 such as Latin-1 bytes.
     */
    private static function count(string $utf8Pattern, string $bytePattern, string $text): int
    {
        $count = preg_match_all($utf8Pattern, $text);

        return $count === false ? (int)preg_match_all($bytePattern, $text) : $count;
    }
}
