<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal
 */
final class LineWrapper
{
    /**
     * Wraps the lines into at most $maxLines lines of at most $maxCharsPerLine visible characters where the words allow it.
     * With $keepDialogueLines, each line that starts with a dialogue dash stays a line of its own.
     * With 2 or more dialogue lines, no line wraps. A single dialogue line wraps like other text.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function wrap(array $lines, int $maxCharsPerLine, int $maxLines, bool $keepDialogueLines = false): array
    {
        $segments = [];
        foreach ($keepDialogueLines ? $lines : [implode(" ", $lines)] as $line) {
            $words = self::measuredWords($line);
            if ($words === []) {
                continue;
            }

            $startsWithDash = preg_match('/^(?:\s|' . Markup::TAG . ')*[' . DialogueDash::CHARACTERS . ']/u', $line) === 1;
            if ($segments === [] || $startsWithDash) {
                $segments[] = $words;
            } else {
                $segments[count($segments) - 1] = [...$segments[count($segments) - 1], ...$words];
            }
        }

        if (count($segments) > 1) {
            return array_map(fn (array $words): string => implode(" ", array_column($words, "text")), $segments);
        }

        $words = $segments[0] ?? [];

        return self::joinLines($words, self::findBreaks($words, $maxCharsPerLine, $maxLines));
    }


    /**
     * Returns wrap() with $keepDialogueLines, or null when the result breaks a limit.
     *
     * @param list<string> $lines
     *
     * @return ?list<string>
     */
    public static function wrapToFit(array $lines, int $maxCharsPerLine, int $maxLines): ?array
    {
        $wrapped = self::wrap($lines, $maxCharsPerLine, $maxLines, true);

        return self::fits($wrapped, $maxCharsPerLine, $maxLines) ? $wrapped : null;
    }


    /**
     * Returns true when the visible characters of the lines per second over $duration stay at or below $maxCharactersPerSecond.
     * Also returns true when $maxCharactersPerSecond is null or the lines have no visible character.
     *
     * @param array<string> $lines
     */
    public static function fitsCharactersPerSecond(array $lines, float $duration, ?float $maxCharactersPerSecond): bool
    {
        if ($maxCharactersPerSecond === null) {
            return true;
        }
        $characters = self::visibleCharacters($lines);

        return $characters === 0 || self::charactersPerSecond($characters, $duration) <= $maxCharactersPerSecond;
    }


    /**
     * @param array<string> $lines
     */
    public static function fits(array $lines, int $maxCharsPerLine, int $maxLines): bool
    {
        if (count($lines) > $maxLines) {
            return false;
        }
        foreach ($lines as $line) {
            if (self::length(self::measuredWords($line)) > $maxCharsPerLine) {
                return false;
            }
        }

        return true;
    }


    /**
     * Returns the visible characters of the lines. Tags count 0 characters.
     *
     * @param array<string> $lines
     */
    public static function visibleCharacters(array $lines): int
    {
        return array_sum(array_map(fn (string $line): int => Markup::visibleLength($line), $lines));
    }


    /**
     * Returns the visible characters of each line that has at least 1 visible character. Tags count 0 characters.
     *
     * @param array<string> $lines
     *
     * @return list<int>
     */
    public static function visibleLineLengths(array $lines): array
    {
        return array_values(array_filter(array_map(fn (string $line): int => Markup::visibleLength($line), $lines)));
    }


    /**
     * Returns $characters divided by $duration, or INF when $duration is 0 or less.
     */
    public static function charactersPerSecond(int $characters, float $duration): float
    {
        return $duration > 0 ? $characters / $duration : INF;
    }


    /**
     * Splits at spaces outside tags. Tags count 0 characters, and an entity such as &amp; counts 1.
     *
     * @return list<array{text: string, length: int}>
     */
    public static function measuredWords(string $text): array
    {
        $tag    = Markup::TAG;
        $entity = Markup::ENTITY;
        $tokens = preg_split("/($tag|$entity| )/", $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $words = [];
        $word  = ["text" => "", "length" => 0];
        foreach ($tokens as $token) {
            if ($token === " ") {
                if ($word["text"] !== "") {
                    $words[] = $word;
                }
                $word = ["text" => "", "length" => 0];
                continue;
            }

            $word["text"]   .= $token;
            $word["length"] += match (true) {
                preg_match("/^$tag\$/", $token) === 1    => 0,
                preg_match("/^$entity\$/", $token) === 1 => 1,
                default                                   => Markup::countCharacters($token),
            };
        }
        if ($word["text"] !== "") {
            $words[] = $word;
        }

        return $words;
    }


    /**
     * Returns the length of the words joined with one space.
     *
     * @param list<array{text: string, length: int}> $words
     */
    public static function length(array $words): int
    {
        return array_sum(array_column($words, "length")) + max(0, count($words) - 1);
    }


    /**
     * Uses the fewest lines up to $maxLines that fit, and among those the breaks with the most equal line lengths.
     *
     * @param list<array{text: string, length: int}> $words
     *
     * @return list<int> the index of the first word of each line
     */
    private static function findBreaks(array $words, int $maxCharsPerLine, int $maxLines): array
    {
        $wordCount = count($words);
        if ($wordCount === 0) {
            return [];
        }

        // $best[$lineCount][$end] holds [overflow, sum of squared lengths, line starts] for words 0 to $end - 1.
        $best = [0 => [0 => [0, 0, []]]];
        for ($lineCount = 1; $lineCount <= min($maxLines, $wordCount); $lineCount++) {
            for ($end = $lineCount; $end <= $wordCount; $end++) {
                $ending = self::bestLastLine($best[$lineCount - 1], $words, $lineCount - 1, $end, $maxCharsPerLine);
                if ($ending !== null) {
                    $best[$lineCount][$end] = $ending;
                }
            }

            if ($best[$lineCount][$wordCount][0] === 0) {
                return $best[$lineCount][$wordCount][2];
            }
        }

        return $best[min($maxLines, $wordCount)][$wordCount][2];
    }


    /**
     * Returns the best breaks for the words before $end when the last line starts at $firstStart or later.
     * Returns null when no earlier breaks reach such a start.
     *
     * @param array<int, array{int, int, list<int>}> $previous the best breaks with one line fewer, by their end
     * @param list<array{text: string, length: int}> $words
     * @return array{int, int, list<int>}|null
     */
    private static function bestLastLine(array $previous, array $words, int $firstStart, int $end, int $maxCharsPerLine): ?array
    {
        $best = null;
        for ($start = $firstStart; $start < $end; $start++) {
            if (!isset($previous[$start])) {
                continue;
            }

            [$overflow, $squares, $starts] = $previous[$start];
            $length    = self::length(array_slice($words, $start, $end - $start));
            $candidate = [$overflow + max(0, $length - $maxCharsPerLine),
                          $squares + $length ** 2,
                          [...$starts, $start]];
            if ($best === null || array_slice($candidate, 0, 2) < array_slice($best, 0, 2)) {
                $best = $candidate;
            }
        }

        return $best;
    }


    /**
     * Closes the core markup tags that are open at the end of a line and opens them again on the next line.
     *
     * @param list<array{text: string, length: int}> $words
     * @param list<int>                              $lineStarts
     *
     * @return list<string>
     */
    private static function joinLines(array $words, array $lineStarts): array
    {
        $lines    = [];
        $openTags = [];
        foreach ($lineStarts as $lineIndex => $start) {
            $end   = $lineStarts[$lineIndex + 1] ?? count($words);
            $line  = implode("", array_column($openTags, "tag"));
            $line .= implode(" ", array_column(array_slice($words, $start, $end - $start), "text"));

            $openTags = Markup::openCoreTags($line);
            if (isset($lineStarts[$lineIndex + 1])) {
                $line .= Markup::closeCoreTags($openTags);
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
