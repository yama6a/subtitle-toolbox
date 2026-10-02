<?php

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Subtitle;

final class ReferenceSync
{
    private const COARSE_STEP = 0.1;
    private const FINE_STEP   = 0.01;


    /**
     * Finds the scale and offset that make the cue times of $target match those of $reference, without a change to $target.
     */
    public static function sync(Subtitle $target, Subtitle $reference, ?ReferenceSyncOptions $options = null): SyncResult
    {
        $options ??= new ReferenceSyncOptions();

        $targetSpans    = self::toSpans($target);
        $referenceSpans = self::toSpans($reference);
        if ($targetSpans === [] || $referenceSpans === []) {
            return new SyncResult(0, 1, 0);
        }

        $targetTime    = self::totalTime($targetSpans);
        $referenceTime = self::totalTime($referenceSpans);
        $best          = new SyncResult(0, 1, 0);

        foreach (self::scaleFactors($options->searchScale) as $scale) {
            $scaled = array_map(fn (array $span): array => [$span[0] * $scale, $span[1] * $scale], $targetSpans);

            $coarse     = self::overlapPerOffset($scaled, $referenceSpans, $options->minOffset, $options->maxOffset);
            $coarseBest = $options->minOffset + array_search(max($coarse), $coarse, true) * self::COARSE_STEP;

            $offset  = $coarseBest;
            $overlap = self::overlap($scaled, $referenceSpans, $offset);
            $from    = max($options->minOffset, $coarseBest - self::COARSE_STEP);
            $to      = min($options->maxOffset, $coarseBest + self::COARSE_STEP);
            $steps   = (int)floor(($to - $from) / self::FINE_STEP + 1e-9);
            for ($n = 0; $n <= $steps; $n++) {
                $candidate        = $from + $n * self::FINE_STEP;
                $candidateOverlap = self::overlap($scaled, $referenceSpans, $candidate);
                if ($candidateOverlap > $overlap + 1e-9) {
                    $offset  = $candidate;
                    $overlap = $candidateOverlap;
                }
            }

            $score = $overlap / ($targetTime * $scale + $referenceTime - $overlap);
            if ($score > $best->getScore() + 1e-9) {
                $best = new SyncResult(round($offset, 3), $scale, min(1, max(0, $score)));
            }
        }

        return $best;
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
