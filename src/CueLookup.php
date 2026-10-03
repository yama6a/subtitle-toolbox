<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use ArrayIterator;
use Iterator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

trait CueLookup
{
    private ?array $cueLookupIndex = null;


    /**
     * @return Iterator<int, SubtitleCue>
     */
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->cues);
    }


    public function count(): int
    {
        return count($this->cues);
    }


    /**
     * Returns the cues on screen at $time, keyed by cue index. A cue is on screen when start <= $time < end.
     *
     * @return array<int, SubtitleCue>
     */
    public function getCuesAt(float $time): array
    {
        return $this->findCuesOverlapping($time, $time, true);
    }


    /**
     * Returns the lowest index of the cues on screen at $time, or null when no cue is on screen.
     */
    public function getCueIndexAt(float $time): ?int
    {
        return array_key_first($this->getCuesAt($time));
    }


    /**
     * Returns the cues that overlap the range from $from to $to, keyed by cue index and not cut.
     *
     * @return array<int, SubtitleCue>
     */
    public function getCuesBetween(float $from, float $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException("The range start $from must not be after the range end $to.");
        }

        return $this->findCuesOverlapping($from, $to, false);
    }


    /**
     * Returns the cues for which $fn returns true, keyed by cue index.
     *
     * @param callable(SubtitleCue): bool $fn
     * @return array<int, SubtitleCue>
     */
    public function findCues(callable $fn): array
    {
        return array_filter($this->cues, $fn);
    }


    /**
     * Removes the cues for which $fn returns false and moves the comments before the removed cues to the next kept cue.
     *
     * @param callable(SubtitleCue): bool $fn
     */
    public function filterCues(callable $fn): self
    {
        foreach ($this->cues as $index => $cue) {
            if (!$fn($cue)) {
                unset($this->cues[$index]);
            }
        }

        return $this->reIndexCues();
    }


    /**
     * @return array<int, SubtitleCue>
     */
    private function findCuesOverlapping(float $from, float $to, bool $includeTo): array
    {
        $index = $this->getCueLookupIndex();
        if ($index["maxEnds"] === null) {
            return array_filter($this->cues, fn (SubtitleCue $cue): bool => $cue->getEnd() > $from &&
                ($includeTo ? $cue->getStart() <= $to : $cue->getStart() < $to));
        }

        $low  = 0;
        $high = count($index["starts"]);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            $start  = $index["starts"][$middle];
            if ($includeTo ? $start <= $to : $start < $to) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $positions = [];
        self::collectEndingAfter($index["maxEnds"], 1, 0, $index["leafCount"], $low, $from, $positions);

        $cues = [];
        foreach ($positions as $position) {
            $cueIndex        = $index["keys"][$position];
            $cues[$cueIndex] = $this->cues[$cueIndex];
        }

        return $cues;
    }


    /**
     * Returns the cues in start order as a tree whose node holds the latest end of its cues.
     * A time setter of any cue or a change of the cue list makes the next call rebuild the tree.
     */
    private function getCueLookupIndex(): array
    {
        $timeEdits = SubtitleCue::timeEditCount();
        $index     = $this->cueLookupIndex;
        // The cached list shares its storage with $this->cues until either changes, so this check costs O(1).
        if ($index !== null && $index["timeEdits"] === $timeEdits && $index["cues"] === $this->cues) {
            return $index;
        }

        $starts = [];
        $ends   = [];
        foreach ($this->cues as $cue) {
            $starts[] = $cue->getStart();
            $ends[]   = $cue->getEnd();
        }

        $count  = count($starts);
        $sorted = true;
        for ($position = 1; $position < $count; $position++) {
            if ($starts[$position] < $starts[$position - 1]) {
                $sorted = false;
                break;
            }
        }

        $leafCount = 1;
        $maxEnds   = null;
        if ($sorted) {
            while ($leafCount < $count) {
                $leafCount <<= 1;
            }
            $maxEnds = array_fill(0, 2 * $leafCount, -INF);
            foreach ($ends as $position => $end) {
                $maxEnds[$leafCount + $position] = $end;
            }
            for ($node = $leafCount - 1; $node >= 1; $node--) {
                $maxEnds[$node] = max($maxEnds[2 * $node], $maxEnds[2 * $node + 1]);
            }
        }

        return $this->cueLookupIndex = [
            "cues"      => $this->cues,
            "timeEdits" => $timeEdits,
            "starts"    => $starts,
            "keys"      => array_keys($this->cues),
            "maxEnds"   => $maxEnds,
            "leafCount" => $leafCount,
        ];
    }


    private static function collectEndingAfter(
        array $maxEnds,
        int $node,
        int $firstPosition,
        int $size,
        int $positionLimit,
        float $time,
        array &$positions
    ): void {
        if ($firstPosition >= $positionLimit || $maxEnds[$node] <= $time) {
            return;
        }
        if ($size === 1) {
            $positions[] = $firstPosition;

            return;
        }

        $half = $size >> 1;
        self::collectEndingAfter($maxEnds, 2 * $node, $firstPosition, $half, $positionLimit, $time, $positions);
        self::collectEndingAfter($maxEnds, 2 * $node + 1, $firstPosition + $half, $half, $positionLimit, $time, $positions);
    }

}
