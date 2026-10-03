<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class HlsWebVttSegmenter
{
    /**
     * Cuts the subtitle into WebVTT segments with an X-TIMESTAMP-MAP header, and lists them in a VOD playlist.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc8216#section-3.5
     */
    public static function segment(Subtitle $subtitle, HlsSegmentOptions $options = new HlsSegmentOptions()): HlsWebVttResult
    {
        $cues          = array_values($subtitle->getCues());
        $segmentMillis = (int) round($options->segmentDuration * 1000);
        $totalMillis   = $options->mediaDuration === null
            ? (int) max([0, ...array_map(fn (SubtitleCue $cue): float => round($cue->getEnd() * 1000), $cues)])
            : (int) round($options->mediaDuration * 1000);
        if ($totalMillis === 0) {
            throw new InvalidArgumentException("The subtitle has no cue that ends after 0 s. " .
                                               "Set the mediaDuration option to segment it.");
        }

        $fileData                = $subtitle->getFormatData(WebVttParser::FORMAT);
        $fileData["headerLines"] = [
            $options->timestampMap->toHeader(),
            ...array_filter(
                $fileData["headerLines"] ?? [],
                fn (string $line): bool => !TimestampMap::isHeader($line)
            ),
        ];

        $segments  = [];
        $durations = [];
        for ($startMillis = 0, $index = 0; $startMillis < $totalMillis; $startMillis += $segmentMillis, $index++) {
            $endMillis = min($startMillis + $segmentMillis, $totalMillis);
            $segment   = (new Subtitle())->setFormatData(WebVttParser::FORMAT, $fileData);
            foreach ($cues as $cueIndex => $cue) {
                $cueStart = (int) round($cue->getStart() * 1000);
                $cueEnd   = (int) round($cue->getEnd() * 1000);
                $isEmpty  = $cueEnd === $cueStart;
                if ($cueStart < $endMillis && ($cueEnd > $startMillis || ($isEmpty && $cueStart >= $startMillis))) {
                    // RFC 8216 section 3.5: a cue keeps its full time range in every segment it overlaps.
                    $segment->addCue((clone $cue)->setIdentifier($cue->getIdentifier() ?? (string) ($cueIndex + 1)), false);
                }
            }

            $name             = $options->fileName($index);
            $segments[$name]  = $segment->shift($options->local)
                                        ->toString(Format::WebVtt, new WriteOptions(bom: false));
            $durations[$name] = ($endMillis - $startMillis) / 1000.0;
        }

        return new HlsWebVttResult($segments, $durations);
    }
}
