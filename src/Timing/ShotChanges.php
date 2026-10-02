<?php

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\ParsingException;

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
        $text  = preg_replace('/^\xEF\xBB\xBF/', "", $text);
        $times = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\d+(?:\.\d+)?$/', $line)) {
                $times[] = (float)$line;
            } elseif (preg_match('/^(\d+):([0-5]\d):([0-5]\d(?:\.\d+)?)$/', $line, $parts)) {
                $times[] = $parts[1] * 3600 + $parts[2] * 60 + (float)$parts[3];
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
