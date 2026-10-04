<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class SubtitleStatistics
{
    private int $cueCount           = 0;
    private int $wordCount          = 0;
    private int $characterCount     = 0;
    private float $totalDisplayTime = 0;
    private float $span             = 0;

    /** @var list<float> */
    private array $charactersPerSecond = [];

    /** @var list<float> */
    private array $wordsPerMinute = [];

    /** @var list<int> */
    private array $charactersPerLine = [];

    /** @var list<float> */
    private array $gaps = [];

    /** @var array<string, int> */
    private array $wordFrequencies = [];


    private function __construct()
    {
    }


    /**
     * Computes the statistics of all cues of $subtitle at the time of the call.
     */
    public static function of(Subtitle $subtitle): self
    {
        $statistics = new self();
        $cues       = array_values($subtitle->getCues());
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        $statistics->cueCount = count($cues);
        if ($cues !== []) {
            $firstStart       = min(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $cues));
            $lastEnd          = max(array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $cues));
            $statistics->span = round($lastEnd - $firstStart, 3);
        }

        $previousEnd = null;
        foreach ($cues as $cue) {
            $duration                      = round($cue->getEnd() - $cue->getStart(), 3);
            $statistics->totalDisplayTime += $duration;

            if ($previousEnd !== null) {
                $statistics->gaps[] = round($cue->getStart() - $previousEnd, 3);
            }
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());

            $statistics->addText($cue, $duration);
        }
        $statistics->totalDisplayTime = round($statistics->totalDisplayTime, 3);
        arsort($statistics->wordFrequencies);

        return $statistics;
    }


    public function getCueCount(): int
    {
        return $this->cueCount;
    }


    public function getWordCount(): int
    {
        return $this->wordCount;
    }


    /**
     * Returns the number of characters without tags, with an entity such as &amp; as one character.
     */
    public function getCharacterCount(): int
    {
        return $this->characterCount;
    }


    /**
     * Returns the sum of the cue durations in seconds.
     */
    public function getTotalDisplayTime(): float
    {
        return $this->totalDisplayTime;
    }


    /**
     * Returns the seconds from the first start to the last end.
     */
    public function getSpan(): float
    {
        return $this->span;
    }


    /**
     * @return array{min: float, average: float, max: float}
     */
    public function getCharactersPerSecond(): array
    {
        return self::range($this->charactersPerSecond);
    }


    /**
     * @return array{min: float, average: float, max: float}
     */
    public function getWordsPerMinute(): array
    {
        return self::range($this->wordsPerMinute);
    }


    /**
     * @return array{min: float, average: float, max: float}
     */
    public function getCharactersPerLine(): array
    {
        return self::range($this->charactersPerLine);
    }


    /**
     * Returns the seconds from the latest end of the earlier cues to the start of each cue, negative for an overlap.
     *
     * @return array{min: float, average: float, max: float}
     */
    public function getGaps(): array
    {
        return self::range($this->gaps);
    }


    /**
     * Returns the $limit most used words in lower case with their counts, the most used first.
     *
     * @return list<array{word: string, count: int}>
     */
    public function getMostUsedWords(int $limit): array
    {
        $words = [];
        foreach (array_slice($this->wordFrequencies, 0, max(0, $limit), true) as $word => $count) {
            $words[] = ["word" => (string) $word, "count" => $count];
        }

        return $words;
    }


    /**
     * Returns all numbers as an array for JSON, with the 10 most used words.
     */
    public function toArray(): array
    {
        return [
            "cueCount"            => $this->getCueCount(),
            "wordCount"           => $this->getWordCount(),
            "characterCount"      => $this->getCharacterCount(),
            "totalDisplayTime"    => $this->getTotalDisplayTime(),
            "span"                => $this->getSpan(),
            "charactersPerSecond" => $this->getCharactersPerSecond(),
            "wordsPerMinute"      => $this->getWordsPerMinute(),
            "charactersPerLine"   => $this->getCharactersPerLine(),
            "gaps"                => $this->getGaps(),
            "mostUsedWords"       => $this->getMostUsedWords(10),
        ];
    }


    private function addText(SubtitleCue $cue, float $duration): void
    {
        $characters = 0;
        foreach ($cue->getLines() as $line) {
            $length = Markup::visibleLength($line);
            if ($length > 0) {
                $this->charactersPerLine[] = $length;
                $characters               += $length;
            }
        }
        if ($characters === 0) {
            return;
        }

        $text  = Markup::plainText(implode("\n", $cue->getLines()));
        $words = Markup::words($text);

        $this->characterCount += $characters;
        $this->wordCount      += count($words);
        foreach ($words as $word) {
            $word = self::normalizeWord($word);
            if ($word !== "") {
                $this->wordFrequencies[$word] = ($this->wordFrequencies[$word] ?? 0) + 1;
            }
        }

        // A cue without duration has no reading speed.
        if ($duration > 0) {
            $this->charactersPerSecond[] = $characters / $duration;
            $this->wordsPerMinute[]      = count($words) / $duration * 60;
        }
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
