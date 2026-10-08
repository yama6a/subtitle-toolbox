<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

final class MuteRange
{
    /**
     * Records that the audio from $start to $end in seconds holds a filtered word.
     *
     * @internal
     */
    public function __construct(
        public readonly float $start,
        public readonly float $end,
    ) {
    }


    /**
     * Writes the ranges as an MPlayer and Kodi edit decision list with action 1, mute, one range per line.
     *
     * @param list<MuteRange> $ranges
     */
    public static function toEdl(array $ranges): string
    {
        return implode("", array_map(
            fn (MuteRange $range): string => sprintf("%.3F %.3F 1\n", $range->start, $range->end),
            $ranges
        ));
    }


    /**
     * Writes an FFmpeg volume filter that mutes the ranges, or "" when there are none.
     *
     * @param list<MuteRange> $ranges
     */
    public static function toFfmpegVolumeFilter(array $ranges): string
    {
        if ($ranges === []) {
            return "";
        }

        $enable = implode("+", array_map(
            fn (MuteRange $range): string => sprintf("between(t,%.3F,%.3F)", $range->start, $range->end),
            $ranges
        ));

        return "volume=enable='$enable':volume=0";
    }
}
