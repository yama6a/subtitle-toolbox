<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class ShotChangeTiming
{
    /**
     * Moves cue times to the shot changes of $options, closes small gaps and puts all times on frames.
     */
    public static function apply(Subtitle $subtitle, ShotChangeOptions $options): ShotChangeReport
    {
        $shots = array_map(fn (float $time): int => self::toFrame($time, $options), $options->shotChanges);
        $shots = array_values(array_unique($shots));
        sort($shots);

        [$cues, $starts, $ends] = self::toFrames($subtitle, $options);
        [$originalStarts, $originalEnds] = [$starts, $ends];
        $count = count($cues);

        for ($i = 0; $i < $count; $i++) {
            $shot = self::firstShotFrom($shots, $ends[$i]);
            if ($shot !== null && $shot - $ends[$i] <= $options->snapWindowFrames) {
                $end = $shot - $options->minGapFrames;
                if (self::isAllowed($i, $starts[$i], $end, $starts, $ends, $options)) {
                    $ends[$i] = $end;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $shot = self::lastShotUntil($shots, $starts[$i]);
            if ($shot !== null && $starts[$i] - $shot <= $options->snapWindowFrames
                && self::isAllowed($i, $shot, $ends[$i], $starts, $ends, $options)) {
                $starts[$i] = $shot;
            }
        }

        if ($options->chain) {
            $ends = self::chain($starts, $ends, $shots, $options);
        }

        self::write($cues, $starts, $ends, $options);

        return new ShotChangeReport(
            count(array_diff_assoc($starts, $originalStarts)),
            count(array_diff_assoc($ends, $originalEnds)),
        );
    }


    /**
     * @param list<int> $starts
     * @param list<int> $ends
     * @param list<int> $shots
     * @return list<int>
     */
    private static function chain(array $starts, array $ends, array $shots, ShotChangeOptions $options): array
    {
        for ($i = 0; $i < count($starts) - 1; $i++) {
            $gap = $starts[$i + 1] - $ends[$i];
            if ($gap <= $options->minGapFrames || $gap >= $options->snapWindowFrames) {
                continue;
            }

            // A cue that ends before a shot change must not run into the next shot.
            $shot = self::firstShotFrom($shots, $ends[$i] + 1);
            if ($shot === null || $shot >= $starts[$i + 1]) {
                $ends[$i] = $starts[$i + 1] - $options->minGapFrames;
            }
        }

        return $ends;
    }


    /**
     * Rejects a move that makes a cue shorter than minDurationFrames, or that brings it closer than minGapFrames to the cue before or after it.
     *
     * @param list<int> $starts
     * @param list<int> $ends
     */
    private static function isAllowed(int $i, int $start, int $end, array $starts, array $ends, ShotChangeOptions $options): bool
    {
        $duration = $end - $start;
        if ($duration <= 0 || ($duration < $options->minDurationFrames && $duration < $ends[$i] - $starts[$i])) {
            return false;
        }

        $gap = $options->minGapFrames;
        if (isset($starts[$i + 1]) && $end > $starts[$i + 1] - $gap && $end > $ends[$i]) {
            return false;
        }

        return !(isset($ends[$i - 1]) && $start < $ends[$i - 1] + $gap && $start < $starts[$i]);
    }


    /** @param list<int> $shots */
    private static function firstShotFrom(array $shots, int $frame): ?int
    {
        foreach ($shots as $shot) {
            if ($shot >= $frame) {
                return $shot;
            }
        }

        return null;
    }


    /** @param list<int> $shots */
    private static function lastShotUntil(array $shots, int $frame): ?int
    {
        $found = null;
        foreach ($shots as $shot) {
            if ($shot > $frame) {
                break;
            }
            $found = $shot;
        }

        return $found;
    }


    /** @return array{list<SubtitleCue>, list<int>, list<int>} */
    private static function toFrames(Subtitle $subtitle, ShotChangeOptions $options): array
    {
        $cues = array_values($subtitle->getCues());
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        $starts = array_map(fn (SubtitleCue $cue): int => self::toFrame($cue->getStart(), $options), $cues);
        $ends   = array_map(fn (SubtitleCue $cue): int => self::toFrame($cue->getEnd(), $options), $cues);

        return [$cues, $starts, $ends];
    }


    private static function toFrame(float $seconds, ShotChangeOptions $options): int
    {
        return (int)round($seconds * $options->frameRate);
    }


    /**
     * @param list<SubtitleCue> $cues
     * @param list<int> $starts
     * @param list<int> $ends
     */
    private static function write(array $cues, array $starts, array $ends, ShotChangeOptions $options): void
    {
        foreach ($cues as $i => $cue) {
            $cue->setStart($starts[$i] / $options->frameRate);
            $cue->setEnd($ends[$i] / $options->frameRate);
        }
    }
}
