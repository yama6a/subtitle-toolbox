<?php

namespace SubtitleToolbox\Hls;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class HlsSegmentOptions
{
    public const DEFAULT_MPEGTS = 900000;

    public readonly TimestampMap $timestampMap;


    /**
     * Creates the segment settings, for example new HlsSegmentOptions(segmentDuration: 6, mpegts: 900000).
     *
     * @param float  $segmentDuration seconds per segment, Apple recommends 6
     * @param int    $mpegts          90 kHz MPEG-2 timestamp at which subtitle time 0 plays
     * @param float  $local           WebVTT cue time in seconds that maps to $mpegts
     * @param string $fileNamePattern sprintf() pattern with one %d for the 0-based segment number
     * @param ?float $mediaDuration   seconds the playlist covers, null for the end of the last cue
     */
    public function __construct(
        public readonly float $segmentDuration = 6,
        public readonly int $mpegts = self::DEFAULT_MPEGTS,
        public readonly float $local = 0,
        public readonly string $fileNamePattern = "sub%d.vtt",
        public readonly ?float $mediaDuration = null,
    ) {
        if (round($segmentDuration, 3) <= 0) {
            throw new InvalidArgumentException("The segment duration must be at least 0.001 s, got $segmentDuration.");
        }

        $placeholders = str_replace("%%", "", $fileNamePattern);
        if (substr_count($placeholders, "%") !== 1 || preg_match("/%\d*d/", $placeholders) !== 1) {
            throw new InvalidArgumentException("The file name pattern must hold exactly one %d and no other " .
                                               "placeholder, got $fileNamePattern.");
        }

        if ($mediaDuration !== null && round($mediaDuration, 3) <= 0) {
            throw new InvalidArgumentException("The media duration must be at least 0.001 s, got $mediaDuration.");
        }

        $this->timestampMap = new TimestampMap($mpegts, $local);
    }


    /**
     * Returns the file name of the segment with the 0-based number $index.
     */
    public function fileName(int $index): string
    {
        return sprintf($this->fileNamePattern, $index);
    }
}
