<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SubtitleDiff
{
    private const MIN_TEXT_SIMILARITY = 0.7;
    private const MIN_OVERLAP_SHARE   = 0.5;
    private const DENSE_LIMIT         = 40000;

    /** @var list<float> */
    private array $oldStarts;

    /** @var list<float> */
    private array $oldEnds;

    /** @var list<float> */
    private array $newStarts;

    /** @var list<float> */
    private array $newEnds;


    /**
     * @param list<SubtitleCue> $oldCues
     * @param list<SubtitleCue> $newCues
     * @param list<string> $oldTexts
     * @param list<string> $newTexts
     */
    private function __construct(
        array $oldCues,
        array $newCues,
        private readonly array $oldTexts,
        private readonly array $newTexts,
        private readonly float $tolerance,
    ) {
        $this->oldStarts = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $oldCues);
        $this->oldEnds   = array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $oldCues);
        $this->newStarts = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $newCues);
        $this->newEnds   = array_map(fn (SubtitleCue $cue): float => $cue->getEnd(), $newCues);
    }


    /**
     * Pairs the cues of $old and $new by time and text, and returns the cues without a partner and the pairs that differ.
     *
     * @return list<CueDifference>
     */
    public static function compare(Subtitle $old, Subtitle $new, ?SubtitleDiffOptions $options = null): array
    {
        $options ??= new SubtitleDiffOptions();

        $oldIndexes = array_keys($old->getCues());
        $newIndexes = array_keys($new->getCues());
        $oldCues    = array_values($old->getCues());
        $newCues    = array_values($new->getCues());
        $oldTexts   = array_map(fn (SubtitleCue $cue): string => self::normalize($cue->getText(), $options), $oldCues);
        $newTexts   = array_map(fn (SubtitleCue $cue): string => self::normalize($cue->getText(), $options), $newCues);
        $diff       = new self($oldCues, $newCues, $oldTexts, $newTexts, $options->timeTolerance);

        $differences = [];
        foreach ($diff->align() as [$i, $j]) {
            if ($j === null) {
                $differences[] = new CueDifference(CueDifference::KIND_REMOVED, $oldIndexes[$i], null, $oldCues[$i], null);
                continue;
            }
            if ($i === null) {
                $differences[] = new CueDifference(CueDifference::KIND_ADDED, null, $newIndexes[$j], null, $newCues[$j]);
                continue;
            }

            $textChanged   = $oldTexts[$i] !== $newTexts[$j] || $oldCues[$i]->isForced() !== $newCues[$j]->isForced();
            $timingChanged = !$options->textOnly && !$diff->isSameTime($i, $j);
            $kind          = match (true) {
                $textChanged && $timingChanged => CueDifference::KIND_TEXT_AND_TIMING_CHANGED,
                $textChanged                   => CueDifference::KIND_TEXT_CHANGED,
                $timingChanged                 => CueDifference::KIND_TIMING_CHANGED,
                default                        => null,
            };
            if ($kind !== null) {
                $differences[] = new CueDifference($kind, $oldIndexes[$i], $newIndexes[$j], $oldCues[$i], $newCues[$j]);
            }
        }

        return $differences;
    }


    /**
     * Returns true when compare() finds no difference between the cues of $a and $b.
     */
    public static function isEqual(Subtitle $a, Subtitle $b, ?SubtitleDiffOptions $options = null): bool
    {
        return self::compare($a, $b, $options) === [];
    }


    /**
     * Writes the result of compare() as plain text, one block per difference, with cue numbers that start at 1.
     *
     * @param list<CueDifference> $differences
     */
    public static function toText(array $differences): string
    {
        $blocks = [];
        foreach ($differences as $difference) {
            $numbers = [];
            if ($difference->getOldIndex() !== null) {
                $numbers[] = "old cue " . ($difference->getOldIndex() + 1);
            }
            if ($difference->getNewIndex() !== null) {
                $numbers[] = "new cue " . ($difference->getNewIndex() + 1);
            }

            $block = $difference->getKind() . ": " . implode(", ", $numbers) . "\n";
            if ($difference->getOldCue() !== null) {
                $block .= self::describeCue("-", $difference->getOldCue());
            }
            if ($difference->getNewCue() !== null) {
                $block .= self::describeCue("+", $difference->getNewCue());
            }
            $blocks[] = $block;
        }

        return implode("\n", $blocks);
    }


    private static function describeCue(string $marker, SubtitleCue $cue): string
    {
        $text = "$marker " . self::formatTime($cue->getStart()) . " --> " . self::formatTime($cue->getEnd())
                . ($cue->isForced() ? " forced" : "") . "\n";
        foreach ($cue->getLines() as $line) {
            $text .= "  $line\n";
        }

        return $text;
    }


    private static function formatTime(float $seconds): string
    {
        return sprintf("%s%02d:%02d:%02d.%03d", $seconds < 0 ? "-" : "", ...Timecode::milliseconds(abs($seconds)));
    }


    private static function normalize(string $text, SubtitleDiffOptions $options): string
    {
        if ($options->ignoreFormatting) {
            $text = Markup::plainText($text);
        }
        if ($options->ignoreWhitespace) {
            $text = preg_replace("/[ \t\n\r\f\v]+/", "", $text);
        }

        return $text;
    }


    /**
     * Returns the pairs of a weighted longest common subsequence in order, with null for the side without a partner.
     * Pairs with the same text split the lists into stretches. A stretch up to DENSE_LIMIT cells gets the full
     * dynamic program. A larger stretch keeps the pairs that overlap in time from the sparse pass.
     *
     * @return list<array{?int, ?int}>
     */
    private function align(): array
    {
        $chain   = $this->heaviestChain($this->sparseCandidates());
        $anchors = array_values(array_filter($chain, fn (array $pair): bool =>
            $this->oldTexts[$pair[0]] === $this->newTexts[$pair[1]]));
        $anchors[] = [count($this->oldTexts), count($this->newTexts)];

        $pairs   = [];
        $oldFrom = 0;
        $newFrom = 0;
        $next    = 0;
        foreach ($anchors as [$oldTo, $newTo]) {
            if (($oldTo - $oldFrom) * ($newTo - $newFrom) <= self::DENSE_LIMIT) {
                array_push($pairs, ...$this->denseAlign($oldFrom, $oldTo, $newFrom, $newTo));
            } else {
                $inside = [];
                while (isset($chain[$next]) && $chain[$next][0] < $oldTo) {
                    $inside[] = $chain[$next++];
                }
                array_push($pairs, ...$this->fillGaps($inside, $oldFrom, $oldTo, $newFrom, $newTo));
            }

            if ($oldTo < count($this->oldTexts)) {
                $pairs[] = [$oldTo, $newTo];
            }
            while (isset($chain[$next]) && $chain[$next][0] <= $oldTo) {
                $next++;
            }
            $oldFrom = $oldTo + 1;
            $newFrom = $newTo + 1;
        }

        return $pairs;
    }


    /**
     * Returns the pairs with the same text and the pairs that overlap in time, with their weights.
     *
     * @return list<array{int, int, float}>
     */
    private function sparseCandidates(): array
    {
        $newByText = [];
        foreach ($this->newTexts as $j => $text) {
            $newByText[$text][] = $j;
        }

        $newOrder = array_keys($this->newStarts);
        usort($newOrder, fn (int $a, int $b): int => $this->newStarts[$a] <=> $this->newStarts[$b]);
        $sortedStarts = array_map(fn (int $j): float => $this->newStarts[$j], $newOrder);
        $longest      = 0.0;
        foreach ($this->newStarts as $j => $start) {
            $longest = max($longest, $this->newEnds[$j] - $start);
        }

        $candidates = [];
        foreach ($this->oldTexts as $i => $text) {
            $partners = array_fill_keys($newByText[$text] ?? [], true);
            for ($k = self::firstAtOrAfter($sortedStarts, $this->oldStarts[$i] - $longest);
                 $k < count($sortedStarts) && $sortedStarts[$k] < $this->oldEnds[$i]; $k++) {
                $partners[$newOrder[$k]] = true;
            }

            foreach (array_keys($partners) as $j) {
                $weight = $this->pairWeight($i, $j);
                if ($weight > 0) {
                    $candidates[] = [$i, $j, $weight];
                }
            }
        }

        return $candidates;
    }


    /** @param list<float> $sorted */
    private static function firstAtOrAfter(array $sorted, float $value): int
    {
        $low  = 0;
        $high = count($sorted);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }


    /**
     * Returns the candidate pairs, rising in both indexes, with the largest sum of weights. A Fenwick tree over the
     * new index holds the best chain that ends at or before each index.
     *
     * @param list<array{int, int, float}> $candidates
     * @return list<array{int, int}>
     */
    private function heaviestChain(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b): int => [$a[0], $b[1]] <=> [$b[0], $a[1]]);

        $size     = count($this->newTexts);
        $tree     = array_fill(1, max(1, $size), [0.0, -1]);
        $scores   = [];
        $previous = [];
        $best     = [0.0, -1];
        foreach ($candidates as $c => [, $j, $weight]) {
            $before = [0.0, -1];
            for ($k = $j; $k > 0; $k -= $k & -$k) {
                if ($tree[$k][0] > $before[0]) {
                    $before = $tree[$k];
                }
            }

            $scores[$c]   = $before[0] + $weight;
            $previous[$c] = $before[1];
            for ($k = $j + 1; $k <= $size; $k += $k & -$k) {
                if ($scores[$c] > $tree[$k][0]) {
                    $tree[$k] = [$scores[$c], $c];
                }
            }
            if ($scores[$c] > $best[0]) {
                $best = [$scores[$c], $c];
            }
        }

        $chain = [];
        for ($c = $best[1]; $c >= 0; $c = $previous[$c]) {
            $chain[] = [$candidates[$c][0], $candidates[$c][1]];
        }

        return array_reverse($chain);
    }


    /**
     * Adds the unpaired cues of a stretch around the given pairs, removed cues before added ones.
     *
     * @param list<array{int, int}> $inside
     * @return list<array{?int, ?int}>
     */
    private function fillGaps(array $inside, int $oldFrom, int $oldTo, int $newFrom, int $newTo): array
    {
        $pairs    = [];
        $inside[] = [$oldTo, $newTo];
        foreach ($inside as [$i, $j]) {
            for (; $oldFrom < $i; $oldFrom++) {
                $pairs[] = [$oldFrom, null];
            }
            for (; $newFrom < $j; $newFrom++) {
                $pairs[] = [null, $newFrom];
            }
            if ($i < $oldTo) {
                $pairs[] = [$i, $j];
            }
            $oldFrom = $i + 1;
            $newFrom = $j + 1;
        }

        return $pairs;
    }


    /** @return list<array{?int, ?int}> */
    private function denseAlign(int $oldFrom, int $oldTo, int $newFrom, int $newTo): array
    {
        $n = $oldTo - $oldFrom;
        $m = $newTo - $newFrom;

        $directions = [];
        $previous   = array_fill(0, $m + 1, 0.0);
        for ($i = 1; $i <= $n; $i++) {
            $current = [0.0];
            $row     = str_repeat("u", $m + 1);
            for ($j = 1; $j <= $m; $j++) {
                $best      = $current[$j - 1];
                $direction = "l";
                if ($previous[$j] > $best) {
                    $best      = $previous[$j];
                    $direction = "u";
                }

                $weight = $this->pairWeight($oldFrom + $i - 1, $newFrom + $j - 1);
                if ($weight > 0 && $previous[$j - 1] + $weight > $best + 1e-9) {
                    $best      = $previous[$j - 1] + $weight;
                    $direction = "d";
                }

                $current[$j] = $best;
                $row[$j]     = $direction;
            }
            $directions[$i] = $row;
            $previous       = $current;
        }

        $pairs = [];
        $i     = $n;
        $j     = $m;
        while ($i > 0 || $j > 0) {
            $direction = $i === 0 ? "l" : ($j === 0 ? "u" : $directions[$i][$j]);
            if ($direction === "d") {
                $pairs[] = [$oldFrom + --$i, $newFrom + --$j];
            } elseif ($direction === "u") {
                $pairs[] = [$oldFrom + --$i, null];
            } else {
                $pairs[] = [null, $newFrom + --$j];
            }
        }

        return array_reverse($pairs);
    }


    /**
     * Returns 0 for cues that do not pair. Otherwise up to 2 for the text likeness plus up to 1 for the time likeness.
     */
    private function pairWeight(int $i, int $j): float
    {
        $textScore = $this->oldTexts[$i] === $this->newTexts[$j]
            ? 1.0
            : self::textSimilarity($this->oldTexts[$i], $this->newTexts[$j]);
        if ($textScore < self::MIN_TEXT_SIMILARITY) {
            $textScore = 0.0;
        }

        $timeScore = 0.0;
        if ($this->isSameTime($i, $j)) {
            $timeScore = 1.0;
        } else {
            $overlap = min($this->oldEnds[$i], $this->newEnds[$j]) - max($this->oldStarts[$i], $this->newStarts[$j]);
            $shorter = min($this->oldEnds[$i] - $this->oldStarts[$i], $this->newEnds[$j] - $this->newStarts[$j]);
            if ($overlap > 0 && $overlap >= self::MIN_OVERLAP_SHARE * $shorter) {
                $timeScore = $overlap / (max($this->oldEnds[$i], $this->newEnds[$j]) -
                                         min($this->oldStarts[$i], $this->newStarts[$j]));
            }
        }

        return $textScore + $textScore + $timeScore;
    }


    private function isSameTime(int $i, int $j): bool
    {
        // The epsilon keeps a difference of exactly the tolerance inside it despite float rounding.
        return abs($this->oldStarts[$i] - $this->newStarts[$j]) <= $this->tolerance + 1e-9
            && abs($this->oldEnds[$i] - $this->newEnds[$j]) <= $this->tolerance + 1e-9;
    }


    /**
     * Returns 1 minus the edit distance divided by the length of the longer text, from 0 to 1, by bytes.
     */
    private static function textSimilarity(string $a, string $b): float
    {
        $longer = max(strlen($a), strlen($b));
        if ($longer === 0 || min(strlen($a), strlen($b)) < self::MIN_TEXT_SIMILARITY * $longer) {
            return 0.0;
        }

        return 1 - levenshtein($a, $b) / $longer;
    }
}
