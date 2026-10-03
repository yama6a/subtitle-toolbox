<?php

namespace SubtitleToolbox\Hls;

use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;

final class HlsWebVttJoiner
{
    /**
     * Joins WebVTT segments in playlist order into one subtitle, with cue times relative to $streamStartPts.
     * A null $streamStartPts uses the MPEGTS value of the first segment.
     *
     * @param iterable<string> $segments
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-3.5
     */
    public static function join(iterable $segments, ?int $streamStartPts = null): Subtitle
    {
        $joined   = new Subtitle();
        $fileData = null;
        $seen     = [];
        foreach ($segments as $content) {
            $segment = Subtitle::fromString($content, Format::WebVtt);
            // RFC 8216 section 3.5: without the header, cue time 0 maps to MPEG-2 timestamp 0.
            $map              = TimestampMap::fromSubtitle($segment) ?? new TimestampMap(0);
            $streamStartPts ??= $map->mpegts;
            $fileData       ??= self::withoutTimestampMap($segment);

            foreach ($segment->shift($map->offset($streamStartPts))->getCues() as $cue) {
                $key = $cue->getStart() . "|" . $cue->getEnd() . "|" . $cue->getText();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $joined->addCue($cue, false);
                }
            }
        }

        return $joined->setFormatData(WebVttParser::FORMAT, $fileData ?? [])->reIndexCues()->removeDuplicateCues();
    }


    private static function withoutTimestampMap(Subtitle $segment): array
    {
        $fileData                = $segment->getFormatData(WebVttParser::FORMAT);
        $fileData["headerLines"] = array_values(array_filter(
            $fileData["headerLines"] ?? [],
            fn (string $line): bool => !TimestampMap::isHeader($line)
        ));
        if ($fileData["headerLines"] === []) {
            unset($fileData["headerLines"]);
        }

        return $fileData;
    }
}
