<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class SpeechReference
{
    /**
     * Returns a reference with one cue without text for the speech between the silences of an ffmpeg silencedetect log.
     */
    public static function fromFfmpegSilencedetect(string $log, float $mediaDuration): Subtitle
    {
        OptionChecks::positiveFinite($mediaDuration, "The media duration must be greater than 0, got %s.");

        $intervals    = [];
        $speechStart  = 0.0;
        $silenceStart = null;
        foreach (explode("\n", StringHelpers::normalizeEOLs($log)) as $index => $line) {
            if (!preg_match('/\bsilence_(start|end):\s*(-?\d+(?:\.\d+)?)/', $line, $match)) {
                continue;
            }

            if (str_contains($line, "channel:")) {
                throw new ParsingException("Cannot read a silencedetect log with one line per channel - run it without mono.",
                                           $index + 1);
            }

            $time = min($mediaDuration, max(0, (float)$match[2]));
            if ($match[1] === "start" && $silenceStart === null) {
                $intervals[]  = [$speechStart, $time];
                $silenceStart = $time;
            } elseif ($match[1] === "end" && $silenceStart !== null) {
                $speechStart  = $time;
                $silenceStart = null;
            } else {
                throw new ParsingException("Found silence_$match[1] out of order - silence_start and silence_end must alternate.",
                                           $index + 1);
            }
        }

        if ($silenceStart === null) {
            $intervals[] = [$speechStart, $mediaDuration];
        }

        return self::fromIntervals(array_values(array_filter($intervals, fn (array $interval): bool => $interval[1] > $interval[0])));
    }


    /**
     * Returns a reference with one cue without text for each [start, end] pair in seconds from a voice activity detector.
     *
     * @param list<array{float|int, float|int}> $intervals
     */
    public static function fromIntervals(array $intervals): Subtitle
    {
        $subtitle = new Subtitle();
        $cues     = [];
        foreach ($intervals as $index => $interval) {
            if (!is_array($interval) || !array_is_list($interval) || count($interval) !== 2 ||
                !is_numeric($interval[0]) || !is_numeric($interval[1]) || $interval[0] < 0 || $interval[1] < $interval[0]) {
                throw new InvalidArgumentException("Interval $index must be [start, end] in seconds with 0 <= start <= end.");
            }

            $cues[] = new SubtitleCue((float)$interval[0], (float)$interval[1]);
        }

        return $subtitle->addCues($cues);
    }
}
