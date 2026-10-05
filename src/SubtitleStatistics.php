<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class SubtitleStatistics
{
    /**
     * @param int                                           $cueCount            The number of cues, also image cues.
     * @param int                                           $wordCount           The number of words of the text without tags.
     * @param int                                           $characterCount      The characters without tags, with an entity such as &amp; as one character.
     * @param float                                         $totalDisplayTime    The sum of the cue durations in seconds.
     * @param float                                         $span                The seconds from the first start to the last end.
     * @param array{min: float, average: float, max: float} $charactersPerSecond The reading speed of the cues with text and a duration.
     * @param array{min: float, average: float, max: float} $wordsPerMinute      The words per minute of the cues with text and a duration.
     * @param array{min: float, average: float, max: float} $charactersPerLine   The characters of the lines with text.
     * @param array{min: float, average: float, max: float} $gaps                The seconds from the latest end of the earlier cues to the start of each cue, negative for an overlap.
     * @param list<array{word: string, count: int}>         $mostUsedWords       Every word in lower case with its count, the most used first.
     */
    private function __construct(
        public readonly int $cueCount,
        public readonly int $wordCount,
        public readonly int $characterCount,
        public readonly float $totalDisplayTime,
        public readonly float $span,
        public readonly array $charactersPerSecond,
        public readonly array $wordsPerMinute,
        public readonly array $charactersPerLine,
        public readonly array $gaps,
        public readonly array $mostUsedWords,
    ) {
    }


    /**
     * Computes the statistics of all cues of $subtitle at the time of the call.
     */
    public static function of(Subtitle $subtitle): self
    {
        $cues = array_values($subtitle->getCues());
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        $span = 0.0;
        if ($cues !== []) {
            $firstStart = min(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $cues));
            $lastEnd    = max(array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $cues));
            $span       = round($lastEnd - $firstStart, 3);
        }

        $totalDisplayTime    = 0.0;
        $gaps                = [];
        $characterCount      = 0;
        $wordCount           = 0;
        $charactersPerSecond = [];
        $wordsPerMinute      = [];
        $charactersPerLine   = [];
        $wordFrequencies     = [];
        $previousEnd         = null;
        foreach ($cues as $cue) {
            $duration          = round($cue->getEnd() - $cue->getStart(), 3);
            $totalDisplayTime += $duration;

            if ($previousEnd !== null) {
                $gaps[] = round($cue->getStart() - $previousEnd, 3);
            }
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());

            $characters = 0;
            foreach ($cue->getLines() as $line) {
                $length = Markup::visibleLength($line);
                if ($length > 0) {
                    $charactersPerLine[] = $length;
                    $characters         += $length;
                }
            }
            if ($characters === 0) {
                continue;
            }

            $words           = Markup::words(Markup::plainText(implode("\n", $cue->getLines())));
            $characterCount += $characters;
            $wordCount      += count($words);
            foreach ($words as $word) {
                $word = self::normalizeWord($word);
                if ($word !== "") {
                    $wordFrequencies[$word] = ($wordFrequencies[$word] ?? 0) + 1;
                }
            }

            // A cue without duration has no reading speed.
            if ($duration > 0) {
                $charactersPerSecond[] = $characters / $duration;
                $wordsPerMinute[]      = count($words) / $duration * 60;
            }
        }
        arsort($wordFrequencies);

        $mostUsedWords = [];
        foreach ($wordFrequencies as $word => $count) {
            $mostUsedWords[] = ["word" => (string) $word, "count" => $count];
        }

        return new self(
            cueCount: count($cues),
            wordCount: $wordCount,
            characterCount: $characterCount,
            totalDisplayTime: round($totalDisplayTime, 3),
            span: $span,
            charactersPerSecond: self::range($charactersPerSecond),
            wordsPerMinute: self::range($wordsPerMinute),
            charactersPerLine: self::range($charactersPerLine),
            gaps: self::range($gaps),
            mostUsedWords: $mostUsedWords,
        );
    }


    /**
     * Returns all numbers as an array for JSON, with the 10 most used words.
     */
    public function toArray(): array
    {
        return [
            "cueCount"            => $this->cueCount,
            "wordCount"           => $this->wordCount,
            "characterCount"      => $this->characterCount,
            "totalDisplayTime"    => $this->totalDisplayTime,
            "span"                => $this->span,
            "charactersPerSecond" => $this->charactersPerSecond,
            "wordsPerMinute"      => $this->wordsPerMinute,
            "charactersPerLine"   => $this->charactersPerLine,
            "gaps"                => $this->gaps,
            "mostUsedWords"       => array_slice($this->mostUsedWords, 0, 10),
        ];
    }


    private static function normalizeWord(string $word): string
    {
        $word = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', "", $word)
            ?? preg_replace('/^[[:punct:]]+|[[:punct:]]+$/', "", $word);

        // mbstring is not part of a default PHP build. Without it, only ASCII letters change case.
        return function_exists("mb_strtolower") && preg_match('//u', $word) === 1
            ? mb_strtolower($word, "UTF-8")
            : strtolower($word);
    }


    /**
     * @param list<int|float> $values
     *
     * @return array{min: float, average: float, max: float}
     */
    private static function range(array $values): array
    {
        if ($values === []) {
            return ["min" => 0.0, "average" => 0.0, "max" => 0.0];
        }

        return [
            "min"     => (float)min($values),
            "average" => (float)(array_sum($values) / count($values)),
            "max"     => (float)max($values),
        ];
    }
}
