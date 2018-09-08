<?php

namespace SubtitleToolbox;

class StringHelpers
{
    const UNIX_LINE_ENDING    = "\n";
    const MAC_LINE_ENDING     = "\r";
    const WINDOWS_LINE_ENDING = "\r\n";


    public static function hasUtf8Bom(string $str)
    {
        $bom = pack("CCC", 0xef, 0xbb, 0xbf);

        return strncmp($str, $bom, 3) === 0;
    }


    public static function removeUtf8Bom(string $str)
    {
        return self::hasUtf8Bom($str) ? substr($str, 3) : $str;
    }


    public static function addUtf8Bom(string $str)
    {
        return self::hasUtf8Bom($str) ? $str : chr(239) . chr(187) . chr(191) . $str;
    }


    /**
     * Trims string, fixes line endings and multi-spaces
     *
     * @param string $str
     *
     * @return string
     */
    public static function cleanString(string $str): string
    {
        $str = static::normalizeEOLs($str); // fix line endings (UNIX vs WINDOWS vs OSX)
        $str = preg_replace('/\t+/', ' ', $str); // replace tabs with spaces
        $str = preg_replace('/ +/', ' ', $str); // strip multi-spaces
        $str = preg_replace('/\n+/', "\n", $str); // strip empty lines

        return trim($str);
    }


    /**
     * Replaces all EOLs with UNIX EOLs.
     *
     * @param string $str
     *
     * @return string
     */
    public static function normalizeEOLs(string $str): string
    {
        return str_replace([static::WINDOWS_LINE_ENDING, static::MAC_LINE_ENDING], static::UNIX_LINE_ENDING, $str);
    }
}
