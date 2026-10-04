<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use Closure;
use Generator;

final class HlsWebVttResult
{
    /**
     * @internal HlsWebVttSegmenter::segment() creates the result.
     *
     * @param Closure(): Generator<string, string> $segments yields file name => WebVTT content
     * @param int                                  $totalMillis the milliseconds that the playlist covers
     */
    public function __construct(
        private readonly Closure $segments,
        private readonly HlsSegmentOptions $options,
        private readonly int $totalMillis,
    ) {
    }


    /**
     * @internal
     */
    public static function segmentMillis(HlsSegmentOptions $options): int
    {
        return (int) round($options->segmentDuration * 1000);
    }


    public function getSegmentCount(): int
    {
        return intdiv($this->totalMillis + self::segmentMillis($this->options) - 1, self::segmentMillis($this->options));
    }


    /**
     * Yields the WebVTT content of each segment, keyed by its file name, in playlist order. Each call writes the
     * segments again, one at a time. iterator_to_array() returns all of them as an array.
     *
     * @return Generator<string, string>
     */
    public function getSegments(): Generator
    {
        yield from ($this->segments)();
    }


    /**
     * Yields the duration in seconds of each segment, keyed by its file name, in playlist order.
     *
     * @return Generator<string, float>
     */
    public function getDurations(): Generator
    {
        $segmentMillis = self::segmentMillis($this->options);
        for ($startMillis = 0, $index = 0; $startMillis < $this->totalMillis; $startMillis += $segmentMillis, $index++) {
            yield $this->options->fileName($index) => (min($startMillis + $segmentMillis, $this->totalMillis) - $startMillis) / 1000.0;
        }
    }


    /**
     * Returns the VOD media playlist (.m3u8) that lists the segments.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-4.3
     */
    public function getPlaylist(): string
    {
        // RFC 8216 section 4.3.3.1: every EXTINF, rounded to the nearest integer, must not exceed the target duration.
        $targetDuration = 1;
        foreach ($this->getDurations() as $duration) {
            $targetDuration = max($targetDuration, (int) round($duration));
        }

        // RFC 8216 section 7: decimal EXTINF durations need protocol version 3.
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:$targetDuration\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n";
        foreach ($this->getDurations() as $name => $duration) {
            $playlist .= sprintf("#EXTINF:%.3f,\n", $duration) . $name . "\n";
        }

        return $playlist . "#EXT-X-ENDLIST\n";
    }
}
