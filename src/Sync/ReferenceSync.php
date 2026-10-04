<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class ReferenceSync
{
    private const COARSE_STEP  = 0.1;
    private const FINE_STEP    = 0.01;
    private const SPLIT_BLOCKS = 200;


    /**
     * Finds the scale and offset that make the cue times of $target match those of the reference in $options, and
     * retimes $target with them.
     */
    public static function apply(Subtitle $target, ReferenceSyncOptions $options): SyncResult
    {
        $result   = self::find($target, $options);
        $segments = $result->getSegments();
        if (count($segments) > 1) {
            self::retimeSegments($target, $result->getScale(), $segments);

            return $result;
        }

        if ($result->getScale() != 1) {
            $target->scale($result->getScale());
        }
        if ($result->getOffset() != 0) {
            $target->shift($result->getOffset());
        }

        return $result;
    }


    /**
     * Scales and then shifts each cue with the segment that holds its start. An earlier cue ends before a later part starts.
     *
     * @param list<array{from: float, to: float, scale: float, offset: float}> $segments
     */
    private static function retimeSegments(Subtitle $target, float $scale, array $segments): void
    {
        $parts = array_fill(0, count($segments), []);
        foreach ($target->getCues() as $cue) {
            $index = count($segments) - 1;
            while ($index > 0 && $cue->getStart() < $segments[$index]["from"]) {
                $index--;
            }
            $parts[$index][] = $cue;
        }

        foreach ($parts as $index => $cues) {
            foreach ($cues as $cue) {
                $cue->setStart(max(0, $cue->getStart() * $scale + $segments[$index]["offset"]))
                    ->setEnd(max(0, $cue->getEnd() * $scale + $segments[$index]["offset"]))
                    ->mapWordTimestamps(fn (float $time): float => $time * $scale + $segments[$index]["offset"]);
            }
        }

        for ($index = 1; $index < count($parts); $index++) {
            if ($parts[$index] === []) {
                continue;
            }

            $laterStart = min(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $parts[$index]));
            for ($earlier = 0; $earlier < $index; $earlier++) {
                foreach ($parts[$earlier] as $cue) {
                    if ($cue->getStart() < $laterStart && $cue->getEnd() > $laterStart - 0.001) {
                        $cue->setEnd(max($cue->getStart(), $laterStart - 0.001));
                    }
                }
            }
        }

        $target->reIndexCues();
    }


    private static function find(Subtitle $target, ReferenceSyncOptions $options): SyncResult
    {
        $targetSpans    = self::toSpans($target);
        $referenceSpans = self::toSpans($options->reference);
        if ($targetSpans === [] || $referenceSpans === []) {
            return new SyncResult(0, 1, 0);
        }

        $targetTime    = self::totalTime($targetSpans);
        $referenceTime = self::totalTime($referenceSpans);
        $best          = new SyncResult(0, 1, 0);
        $bestSplit     = null;

        foreach (self::scaleFactors($options->searchScale) as $scale) {
            $scaled = array_map(fn (array $span): array => [$span[0] * $scale, $span[1] * $scale], $targetSpans);

            $coarse     = self::overlapPerOffset($scaled, $referenceSpans, $options->minOffset, $options->maxOffset);
            $coarseBest = $options->minOffset + array_search(max($coarse), $coarse, true) * self::COARSE_STEP;

            [$offset, $overlap] = self::refine($scaled, $referenceSpans, $coarseBest, $options);

            $score = $overlap / ($targetTime * $scale + $referenceTime - $overlap);
            if ($score > $best->getScore() + 1e-9) {
                $best = new SyncResult(round($offset, 3), $scale, min(1, max(0, $score)));
            }

            if ($options->maxSplits > 0) {
                foreach (self::splitCandidates($targetSpans, $scaled, $referenceSpans, $options) as [$parts, $partsOverlap]) {
                    $partsScore = $partsOverlap / ($targetTime * $scale + $referenceTime - $partsOverlap);
                    $value      = $partsScore - (count($parts) - 1) * $options->splitPenalty;
                    if ($bestSplit === null || $value > $bestSplit[0] + 1e-9) {
                        $bestSplit = [$value, new SyncResult($parts[0]["offset"], $scale, min(1, max(0, $partsScore)), $parts)];
                    }
                }
            }
        }

        if ($bestSplit !== null && $bestSplit[0] > $best->getScore() + 1e-9) {
            return $bestSplit[1];
        }

        return $best;
    }


    /**
     * Returns the best parts for each number of splits from 1 to maxSplits, with the overlap of all parts together.
     * A search over single spans takes too long in PHP. So the splits first fall on block boundaries, and then each
     * split moves to the best span near its boundary.
     *
     * @param list<array{float, float}> $original
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return list<array{list<array{from: float, offset: float}>, float}>
     */
    private static function splitCandidates(array $original, array $target, array $reference, ReferenceSyncOptions $options): array
    {
        $blockSize = (int)ceil(count($target) / self::SPLIT_BLOCKS);
        $blocks    = array_chunk($target, $blockSize);
        $layers    = $options->maxSplits + 1;
        $offsets   = (int)floor(($options->maxOffset - $options->minOffset) / self::COARSE_STEP + 1e-9) + 1;
        $values    = array_fill(0, $layers, array_fill(0, $offsets, 0.0));
        $starts    = array_fill(0, $layers, array_fill(0, $offsets, 0));
        $parents   = array_fill(0, $layers, array_fill(0, $offsets, -1));
        $floors    = array_fill(0, $layers, [-INF, -1]);
        $nodes     = [];

        $best = function (int $layer) use (&$values, &$starts, &$parents, &$floors, &$nodes): array {
            $value = max($values[$layer]);
            if ($value < $floors[$layer][0]) {
                return $floors[$layer];
            }

            $n       = array_search($value, $values[$layer], true);
            $nodes[] = [$n, $starts[$layer][$n], $parents[$layer][$n]];

            return [$value, count($nodes) - 1];
        };

        foreach ($blocks as $blockIndex => $block) {
            for ($layer = $layers - 1; $layer >= 1 && $blockIndex > 0; $layer--) {
                $floors[$layer] = $best($layer - 1);
            }

            $overlaps = self::overlapPerOffset($block, $reference, $options->minOffset, $options->maxOffset);
            for ($layer = 0; $layer < $layers; $layer++) {
                [$floor, $floorNode] = $floors[$layer];
                $row                 = &$values[$layer];
                foreach ($overlaps as $n => $overlap) {
                    if ($floor > $row[$n] + 1e-9) {
                        $row[$n]             = $floor;
                        $starts[$layer][$n]  = $blockIndex;
                        $parents[$layer][$n] = $floorNode;
                    }
                    $row[$n] += $overlap;
                }
                unset($row);
            }
        }

        $candidates = [];
        for ($layer = 1; $layer < $layers; $layer++) {
            $chain = [];
            for ($node = $best($layer)[1]; $node >= 0; $node = $nodes[$node][2]) {
                [$n, $startBlock] = $nodes[$node];
                if ($chain !== [] && $chain[0]["n"] === $n) {
                    $chain[0]["start"] = $startBlock * $blockSize;
                } else {
                    array_unshift($chain, ["n" => $n, "start" => $startBlock * $blockSize]);
                }
            }
            if (count($chain) < 2) {
                continue;
            }

            foreach ($chain as $index => &$part) {
                $part["offset"] = $options->minOffset + $part["n"] * self::COARSE_STEP;
                if ($index > 0) {
                    $limit         = ($chain[$index + 1]["start"] ?? count($target)) - 1;
                    $part["start"] = self::bestSplit($target, $reference, $chain[$index - 1], $part, $blockSize, $limit);
                }
            }
            unset($part);

            $parts   = [];
            $overlap = 0.0;
            foreach ($chain as $index => $part) {
                $end                    = $chain[$index + 1]["start"] ?? count($target);
                [$offset, $partOverlap] = self::refine(array_slice($target, $part["start"], $end - $part["start"]),
                                                       $reference, $part["offset"], $options);
                $parts[]                = ["from" => $index === 0 ? 0.0 : $original[$part["start"]][0], "offset" => round($offset, 3)];
                $overlap               += $partOverlap;
            }
            $candidates[] = [$parts, $overlap];
        }

        return $candidates;
    }


    /**
     * Returns the span index within one block of the split between $before and $after where the overlap is highest.
     * The split stays after the first span of $before and at or before $limit, the last span before the next split.
     *
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @param array{start: int, offset: float} $before
     * @param array{start: int, offset: float} $after
     */
    private static function bestSplit(array $target, array $reference, array $before, array $after, int $blockSize, int $limit): int
    {
        $from   = max($before["start"] + 1, $after["start"] - $blockSize);
        $to     = min($limit, $after["start"] + $blockSize);
        $window = array_slice($target, $from, $to - $from);
        $early  = self::overlapPerSpan($window, $reference, $before["offset"]);
        $late   = self::overlapPerSpan($window, $reference, $after["offset"]);

        $total     = array_sum($late);
        $bestTotal = $total;
        $split     = $from;
        foreach (array_keys($window) as $index) {
            $total += $early[$index] - $late[$index];
            if ($total > $bestTotal + 1e-9) {
                $bestTotal = $total;
                $split     = $from + $index + 1;
            }
        }

        return $split;
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return array{float, float} the offset and its overlap
     */
    private static function refine(array $target, array $reference, float $coarseBest, ReferenceSyncOptions $options): array
    {
        $offset  = $coarseBest;
        $overlap = self::overlap($target, $reference, $offset);
        $from    = max($options->minOffset, $coarseBest - self::COARSE_STEP);
        $to      = min($options->maxOffset, $coarseBest + self::COARSE_STEP);
        $steps   = (int)floor(($to - $from) / self::FINE_STEP + 1e-9);
        for ($n = 0; $n <= $steps; $n++) {
            $candidate        = $from + $n * self::FINE_STEP;
            $candidateOverlap = self::overlap($target, $reference, $candidate);
            if ($candidateOverlap > $overlap + 1e-9) {
                $offset  = $candidate;
                $overlap = $candidateOverlap;
            }
        }

        return [$offset, $overlap];
    }


    /** @return list<float> */
    private static function scaleFactors(bool $searchScale): array
    {
        if (!$searchScale) {
            return [1];
        }

        $factors = [1];
        foreach ([[24, 23.976], [25, 24], [25, 23.976]] as [$faster, $slower]) {
            $factors[] = $faster / $slower;
            $factors[] = $slower / $faster;
        }

        return $factors;
    }


    /**
     * Returns the cue times as sorted spans without overlaps, so time that two cues of one file share counts once.
     *
     * @return list<array{float, float}>
     */
    private static function toSpans(Subtitle $subtitle): array
    {
        $spans = [];
        foreach ($subtitle->getCues() as $cue) {
            if ($cue->getEnd() > $cue->getStart()) {
                $spans[] = [$cue->getStart(), $cue->getEnd()];
            }
        }

        sort($spans);

        $merged = [];
        foreach ($spans as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }


    /** @param list<array{float, float}> $spans */
    private static function totalTime(array $spans): float
    {
        return array_sum(array_map(fn (array $span): float => $span[1] - $span[0], $spans));
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     */
    private static function overlap(array $target, array $reference, float $offset): float
    {
        $overlap        = 0.0;
        $targetCount    = count($target);
        $referenceCount = count($reference);
        $i              = 0;
        $j              = 0;

        while ($i < $targetCount && $j < $referenceCount) {
            $targetEnd = $target[$i][1] + $offset;
            $from      = max($target[$i][0] + $offset, $reference[$j][0]);
            $to        = min($targetEnd, $reference[$j][1]);
            if ($to > $from) {
                $overlap += $to - $from;
            }

            if ($targetEnd < $reference[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $overlap;
    }


    /**
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return list<float>
     */
    private static function overlapPerSpan(array $target, array $reference, float $offset): array
    {
        $overlaps       = array_fill(0, count($target), 0.0);
        $targetCount    = count($target);
        $referenceCount = count($reference);
        $i              = 0;
        $j              = 0;

        while ($i < $targetCount && $j < $referenceCount) {
            $targetEnd = $target[$i][1] + $offset;
            $from      = max($target[$i][0] + $offset, $reference[$j][0]);
            $to        = min($targetEnd, $reference[$j][1]);
            if ($to > $from) {
                $overlaps[$i] += $to - $from;
            }

            if ($targetEnd < $reference[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $overlaps;
    }


    /**
     * Returns the overlap for each offset from $minOffset to $maxOffset in steps of COARSE_STEP.
     * One sweep per offset is too slow in PHP. So the overlap of each span pair that can meet becomes four ramps
     * max(0, o - k) at the corners k = c - b, c - a, d - b and d - a, which add up in second differences on the grid.
     *
     * @param list<array{float, float}> $target
     * @param list<array{float, float}> $reference
     * @return list<float>
     */
    private static function overlapPerOffset(array $target, array $reference, float $minOffset, float $maxOffset): array
    {
        $step           = self::COARSE_STEP;
        $last           = (int)floor(($maxOffset - $minOffset) / $step + 1e-9);
        $maxGridOffset  = $minOffset + $last * $step;
        $secondDiffs    = array_fill(0, $last + 2, 0.0);
        $firstValue     = 0.0;
        $firstSlope     = 0.0;
        $referenceCount = count($reference);
        $firstCandidate = 0;

        $addRamp = function (float $weight, float $corner) use (&$secondDiffs, &$firstValue, &$firstSlope, $minOffset, $step, $last): void {
            $position = ($corner - $minOffset) / $step;
            if ($position < 0) {
                $firstValue += $weight * ($minOffset - $corner);
                $firstSlope += $weight * $step;

                return;
            }

            $index = (int)floor($position);
            if ($index >= $last) {
                return;
            }

            $fraction = $position - $index;
            if ($index === 0) {
                $firstSlope += $weight * (1 - $fraction) * $step;
            } else {
                $secondDiffs[$index] += $weight * (1 - $fraction) * $step;
            }
            $secondDiffs[$index + 1] += $weight * $fraction * $step;
        };

        foreach ($target as [$a, $b]) {
            while ($firstCandidate < $referenceCount && $reference[$firstCandidate][1] - $a <= $minOffset) {
                $firstCandidate++;
            }

            for ($j = $firstCandidate; $j < $referenceCount && $reference[$j][0] - $b < $maxGridOffset; $j++) {
                [$c, $d] = $reference[$j];
                $addRamp(1, $c - $b);
                $addRamp(-1, $c - $a);
                $addRamp(-1, $d - $b);
                $addRamp(1, $d - $a);
            }
        }

        $values = [$firstValue];
        if ($last >= 1) {
            $values[] = $firstValue + $firstSlope;
        }
        for ($n = 1; $n < $last; $n++) {
            $values[] = 2 * $values[$n] - $values[$n - 1] + $secondDiffs[$n];
        }

        return $values;
    }
}
