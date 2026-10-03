<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

trait Fixes
{
    /**
     * Moves the end of each cue to at least $minGap seconds before the start of the next cue, but not before its own start.
     */
    public function fixOverlaps(float $minGap = 0): self
    {
        $this->fixesAssertGap($minGap);

        $cues = $this->fixesCuesInStartOrder();
        foreach ($cues as $index => $cue) {
            if (!isset($cues[$index + 1])) {
                continue;
            }

            $latestEnd = round($cues[$index + 1]->getStart() - $minGap, 3);
            if ($cue->getEnd() > $latestEnd) {
                $cue->setEnd(max($cue->getStart(), $latestEnd));
            }
        }

        return $this;
    }


    /**
     * Makes each cue last at least $minDuration seconds, but ends it at least $minGap seconds before the next cue starts.
     */
    public function extendShortCues(float $minDuration, float $minGap = 0): self
    {
        if ($minDuration <= 0) {
            throw new InvalidArgumentException("The minimum duration must be greater than 0, got $minDuration.");
        }
        $this->fixesAssertGap($minGap);

        $cues = $this->fixesCuesInStartOrder();
        foreach ($cues as $index => $cue) {
            $end = $cue->getStart() + $minDuration;
            if (isset($cues[$index + 1])) {
                $end = min($end, $cues[$index + 1]->getStart() - $minGap);
            }

            $hasSameStartAsPrevious = isset($cues[$index - 1]) && $cues[$index - 1]->getStart() === $cue->getStart();
            if (!$hasSameStartAsPrevious && round($end, 3) > $cue->getEnd()) {
                $cue->setEnd($end);
            }
        }

        return $this;
    }


    /**
     * Breaks the lines of each cue that has a line longer than $maxCharsPerLine or more than $maxLines lines.
     */
    public function wrapLines(int $maxCharsPerLine, int $maxLines = 2): self
    {
        if ($maxCharsPerLine < 1 || $maxLines < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharsPerLine and $maxLines.");
        }

        foreach ($this->getCues() as $cue) {
            $lines     = $cue->getLines();
            $longLines = array_filter($lines, fn (string $line): bool =>
                self::fixesLineLength(self::fixesSplitIntoWords($line)) > $maxCharsPerLine);

            if ($longLines !== [] || count($lines) > $maxLines) {
                $words      = self::fixesSplitIntoWords(implode(" ", $lines));
                $lineStarts = self::fixesFindBreaks($words, $maxCharsPerLine, $maxLines);
                $cue->setLinesByArray(self::fixesJoinLines($words, $lineStarts));
            }
        }

        return $this;
    }


    /**
     * Joins all lines of each cue into one line, with a space between them.
     */
    public function unwrapLines(): self
    {
        foreach ($this->getCues() as $cue) {
            $cue->setLinesByArray([implode(" ", $cue->getLines())]);
        }

        return $this;
    }


    private function fixesAssertGap(float $minGap): void
    {
        if ($minGap < 0) {
            throw new InvalidArgumentException("The minimum gap must not be negative, got $minGap.");
        }
    }


    /**
     * @return list<SubtitleCue>
     */
    private function fixesCuesInStartOrder(): array
    {
        $cues = array_values($this->getCues());
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        return $cues;
    }


    /**
     * Splits at spaces outside tags. Tags count 0 characters, and an entity such as &amp; counts 1.
     *
     * @return list<array{text: string, length: int}>
     */
    private static function fixesSplitIntoWords(string $text): array
    {
        $entity = '&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);';
        $tokens = preg_split("/(<[^>]*>|$entity| )/", $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

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
                preg_match('/^<[^>]*>$/', $token) === 1   => 0,
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
     * @param list<array{text: string, length: int}> $words
     */
    private static function fixesLineLength(array $words): int
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
    private static function fixesFindBreaks(array $words, int $maxCharsPerLine, int $maxLines): array
    {
        $wordCount = count($words);
        if ($wordCount === 0) {
            return [];
        }

        // $best[$lineCount][$end] holds [overflow, sum of squared lengths, line starts] for words 0 to $end - 1.
        $best = [0 => [0 => [0, 0, []]]];
        for ($lineCount = 1; $lineCount <= min($maxLines, $wordCount); $lineCount++) {
            for ($end = $lineCount; $end <= $wordCount; $end++) {
                for ($start = $lineCount - 1; $start < $end; $start++) {
                    if (!isset($best[$lineCount - 1][$start])) {
                        continue;
                    }

                    [$overflow, $squares, $starts] = $best[$lineCount - 1][$start];
                    $length    = self::fixesLineLength(array_slice($words, $start, $end - $start));
                    $candidate = [$overflow + max(0, $length - $maxCharsPerLine),
                                  $squares + $length ** 2,
                                  [...$starts, $start]];
                    if (!isset($best[$lineCount][$end]) || array_slice($candidate, 0, 2) < array_slice($best[$lineCount][$end], 0, 2)) {
                        $best[$lineCount][$end] = $candidate;
                    }
                }
            }

            if ($best[$lineCount][$wordCount][0] === 0) {
                return $best[$lineCount][$wordCount][2];
            }
        }

        return $best[min($maxLines, $wordCount)][$wordCount][2];
    }


    /**
     * Closes the core markup tags that are open at the end of a line and opens them again on the next line.
     *
     * @param list<array{text: string, length: int}> $words
     * @param list<int>                              $lineStarts
     *
     * @return list<string>
     */
    private static function fixesJoinLines(array $words, array $lineStarts): array
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
