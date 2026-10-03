<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

final class HlsWebVttResult
{
    /**
     * @param array<string, string> $segments  file name => WebVTT content, in playlist order
     * @param array<string, float>  $durations file name => segment duration in seconds
     */
    public function __construct(
        private readonly array $segments,
        private readonly array $durations,
    ) {
    }


    /**
     * Returns the WebVTT content of each segment, keyed by its file name, in playlist order.
     *
     * @return array<string, string>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }


    /**
     * Returns the duration in seconds of each segment, keyed by its file name.
     *
     * @return array<string, float>
     */
    public function getDurations(): array
    {
        return $this->durations;
    }


    /**
     * Returns the VOD media playlist (.m3u8) that lists the segments.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-4.3
     */
    public function getPlaylist(): string
    {
        // RFC 8216 section 4.3.3.1: every EXTINF, rounded to the nearest integer, must not exceed the target duration.
        $rounded        = array_map(fn (float $duration): int => (int) round($duration), array_values($this->durations));
        $targetDuration = max(1, ...$rounded);

        // RFC 8216 section 7: decimal EXTINF durations need protocol version 3.
        $lines = ["#EXTM3U", "#EXT-X-VERSION:3", "#EXT-X-TARGETDURATION:$targetDuration", "#EXT-X-MEDIA-SEQUENCE:0",
                  "#EXT-X-PLAYLIST-TYPE:VOD"];
        foreach ($this->durations as $name => $duration) {
            $lines[] = sprintf("#EXTINF:%.3f,", $duration);
            $lines[] = $name;
        }
        $lines[] = "#EXT-X-ENDLIST";

        return implode("\n", $lines) . "\n";
    }
}
