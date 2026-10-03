<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Splits seconds into hours, minutes, seconds and one smaller unit. Each method rounds the total to its unit first,
 * so 1.996 s becomes 2 s and 0 centiseconds, never 1 s and 100 centiseconds.
 *
 * @internal
 */
final class Timecode
{
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
        $labels = (int) round($frameRate->getFps());
        if ($dropFrame) {
            if ($labels !== 30 && $labels !== 60) {
                throw new InvalidArgumentException("Drop-frame time code needs 29.97 or 59.94 fps, got {$frameRate->getFps()}.");
            }

            $dropped       = intdiv($labels, 15);
            $perTenMinutes = 600 * $labels - 9 * $dropped;
            $perMinute     = 60 * $labels - $dropped;
            $rest          = $frame % $perTenMinutes;
            $frame        += 9 * $dropped * intdiv($frame, $perTenMinutes) + ($rest >= $dropped ? $dropped * intdiv($rest - $dropped, $perMinute) : 0);
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
        if ($frame >= round($frameRate->getFps())) {
            $whole++;
            $frame = 0;
        }

        return [...self::seconds($whole), $frame];
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
