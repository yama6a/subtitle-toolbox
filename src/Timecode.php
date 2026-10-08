<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Splits seconds into hours, minutes, seconds and one smaller unit, and turns such parts back into seconds. Each split
 * method rounds the total to its unit first, so 1.996 s becomes 2 s and 0 centiseconds, never 1 s and 100 centiseconds.
 *
 * @internal
 */
final class Timecode
{
    // Comparisons of computed seconds and scores add this margin, so that float rounding errors do not change the result.
    public const EPSILON = 1e-9;

    private const SECONDS_PER_MINUTE = 60;

    // Drop-frame time code keeps every label in each tenth minute and drops labels in the other 9 minutes.
    private const DROP_CYCLE_MINUTES = 10;
    private const DROPPING_MINUTES   = self::DROP_CYCLE_MINUTES - 1;

    // A dropping minute skips 1 label for each 15 labels per second: 2 labels at 30, 4 at 60.
    private const LABELS_PER_DROPPED_LABEL = 15;

    /**
     * @return array{int, int, int} hours, minutes, seconds
     */
    public static function seconds(float $seconds): array
    {
        return array_slice(self::split((int) round($seconds), 1), 0, 3);
    }


    /**
     * @return array{int, int, int, int} hours, minutes, seconds, centiseconds
     */
    public static function centiseconds(float $seconds): array
    {
        return self::split((int) round($seconds * 100), 100);
    }


    /**
     * @return array{int, int, int, int} hours, minutes, seconds, milliseconds
     */
    public static function milliseconds(float $seconds): array
    {
        return self::split(self::totalMilliseconds($seconds), 1000);
    }


    /**
     * Rounds seconds to whole milliseconds, the precision of cue times. For example 0.8333 becomes 0.833.
     */
    public static function roundToMilliseconds(float $seconds): float
    {
        return round($seconds, 3);
    }


    public static function totalMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }


    /**
     * Returns the SMPTE time code of the frame nearest to $seconds.
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     * @throws InvalidArgumentException when $dropFrame is true and the frame rate is not about 29.97 or 59.94 fps.
     */
    public static function frames(float $seconds, FrameRate $frameRate, bool $dropFrame = false): array
    {
        return self::frameNumber($frameRate->secondsToFrames($seconds), $frameRate, $dropFrame);
    }


    /**
     * Returns the SMPTE time code of a frame number. A second holds the frame rate rounded to a whole number of frame
     * labels, so 23.976 fps counts 24 labels. Drop-frame time code skips the first labels of each minute except every
     * tenth minute, so that it stays in step with the clock: 2 labels at 29.97 fps, 4 at 59.94 fps.
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     * @throws InvalidArgumentException when $dropFrame is true and the frame rate is not about 29.97 or 59.94 fps.
     */
    public static function frameNumber(int $frame, FrameRate $frameRate, bool $dropFrame = false): array
    {
        $labels = self::labels($frameRate);
        if ($dropFrame) {
            if ($labels !== 30 && $labels !== 60) {
                throw new InvalidArgumentException("Drop-frame time code needs 29.97 or 59.94 fps, got {$frameRate->getFramesPerSecond()}.");
            }

            $dropped   = intdiv($labels, self::LABELS_PER_DROPPED_LABEL);
            $perCycle  = self::DROP_CYCLE_MINUTES * self::SECONDS_PER_MINUTE * $labels - self::DROPPING_MINUTES * $dropped;
            $perMinute = self::SECONDS_PER_MINUTE * $labels - $dropped;
            $rest      = $frame % $perCycle;
            $frame    += self::DROPPING_MINUTES * $dropped * intdiv($frame, $perCycle)
                         + ($rest >= $dropped ? $dropped * intdiv($rest - $dropped, $perMinute) : 0);
        }

        return self::split($frame, $labels);
    }


    /**
     * Returns whole clock seconds and the frames after the last whole second. The frame count restarts at each clock
     * second, as CsvParser::parseTime() reads it. At a whole frame rate the result equals frames().
     *
     * @return array{int, int, int, int} hours, minutes, seconds, frames
     */
    public static function clockSecondsAndFrames(float $seconds, FrameRate $frameRate): array
    {
        $milliseconds = self::totalMilliseconds($seconds);
        $whole        = intdiv($milliseconds, 1000);
        $frame        = $frameRate->secondsToFrames($milliseconds % 1000 / 1000);
        if ($frame >= self::labels($frameRate)) {
            $whole++;
            $frame = 0;
        }

        return [...self::seconds($whole), $frame];
    }


    /**
     * Returns the seconds of a time in parts. $fraction holds the decimal digits after the point, so "5" adds 0.5 s and
     * "005" adds 0.005 s. The result is the float nearest to the decimal value.
     *
     * @internal
     */
    public static function toSeconds(int $hours, int $minutes, int $seconds, string $fraction = ""): float
    {
        $whole = $hours * 3600 + $minutes * 60 + $seconds;

        return $fraction === "" ? (float) $whole : (float) "$whole.$fraction";
    }


    /**
     * Returns the seconds of a time code that counts frames after the last whole second, for example 00:00:01:12 at
     * 25 fps is 1.48 s.
     *
     * @internal
     */
    public static function toSecondsFromFrames(int $hours, int $minutes, int $seconds, int $frames, FrameRate $frameRate): float
    {
        return $hours * 3600 + $minutes * 60 + $seconds + $frameRate->framesToSeconds($frames);
    }


    /**
     * Formats the whole seconds as m:ss below 1 hour and as h:mm:ss from 1 hour, for example 62.9 becomes "1:02" and
     * 3725 becomes "1:02:05".
     *
     * @internal
     */
    public static function shortClock(float $seconds): string
    {
        [$hours, $minutes, $wholeSeconds] = self::seconds(floor($seconds));

        return $hours > 0 ? sprintf("%d:%02d:%02d", $hours, $minutes, $wholeSeconds) : sprintf("%d:%02d", $minutes, $wholeSeconds);
    }


    /**
     * Returns the frame labels in one second: the frame rate rounded to a whole number, so 23.976 fps gives 24.
     */
    private static function labels(FrameRate $frameRate): int
    {
        return (int) round($frameRate->getFramesPerSecond());
    }


    /**
     * @return array{int, int, int, int}
     */
    private static function split(int $total, int $perSecond): array
    {
        $seconds = intdiv($total, $perSecond);

        return [intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60, $total % $perSecond];
    }
}
