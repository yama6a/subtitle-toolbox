<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal
 */
final class TimeRanges
{
    /**
     * Sorts the ranges and joins each range that starts at or before the end of the previous one.
     * For example, [[3, 5], [1, 2], [2, 4]] becomes [[1, 5]].
     *
     * @param list<array{float, float}> $ranges
     *
     * @return list<array{float, float}>
     */
    public static function merged(array $ranges): array
    {
        sort($ranges);

        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }
}
