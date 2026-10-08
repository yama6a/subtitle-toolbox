<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Timecode;

final class ShotChanges
{
    /**
     * Returns the sorted pts_time values of an ffmpeg log from the select and showinfo filters, in seconds.
     *
     * @return list<float>
     */
    public static function fromFfmpegLog(string $log): array
    {
        preg_match_all('/\bpts_time:\s*(\d+(?:\.\d+)?)/', $log, $matches);

        return self::sorted(array_map('floatval', $matches[1]));
    }


    /**
     * Returns the sorted times of a text with one time per line, in seconds or as hh:mm:ss.mmm.
     *
     * @return list<float>
     */
    public static function fromText(string $text): array
    {
        $times = [];
        foreach (explode("\n", StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($text))) as $index => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\d+(?:\.\d+)?$/', $line)) {
                $times[] = (float)$line;
            } elseif (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:\.(\d+))?$/', $line, $parts)) {
                $times[] = Timecode::toSeconds((int) $parts[1], (int) $parts[2], (int) $parts[3], $parts[4] ?? "");
            } else {
                throw new ParsingException("Cannot read the shot change time \"$line\" - use seconds or hh:mm:ss.mmm.",
                                           $index + 1);
            }
        }

        return self::sorted($times);
    }


    /**
     * @param list<float> $times
     * @return list<float>
     */
    private static function sorted(array $times): array
    {
        sort($times);

        return array_values(array_unique($times, SORT_REGULAR));
    }
}
