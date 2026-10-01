<?php

namespace SubtitleToolbox;

class StringHelpers
{
    public const UNIX_LINE_ENDING    = "\n";
    public const MAC_LINE_ENDING     = "\r";
    public const WINDOWS_LINE_ENDING = "\r\n";

    private const UTF8_BOM = "\xEF\xBB\xBF";


    public static function hasUtf8Bom(string $str): bool
    {
        return str_starts_with($str, self::UTF8_BOM);
    }


    public static function removeUtf8Bom(string $str): string
    {
        return self::hasUtf8Bom($str) ? substr($str, 3) : $str;
    }


    public static function addUtf8Bom(string $str): string
    {
        return self::hasUtf8Bom($str) ? $str : self::UTF8_BOM . $str;
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
        $str = static::normalizeEOLs($str);
        $str = static::normalizeSpaces($str);
        $str = static::removeEmptyLines($str);

        return trim($str);
    }


    public static function trimEachLine(string $str): string
    {
        $lines = explode(StringHelpers::UNIX_LINE_ENDING, $str);
        $lines = array_map("trim", $lines);

        return implode(StringHelpers::UNIX_LINE_ENDING, $lines);
    }


    public static function removeEmptyLines(string $str): string
    {
        return preg_replace('/\n+/', "\n", $str);
    }


    public static function removeDoubleEmptyLines(string $str): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $str);
    }


    public static function normalizeSpaces(string $str): string
    {
        $str = preg_replace('/\t+/', ' ', $str); // replace tabs with spaces
        $str = preg_replace('/ +/', ' ', $str); // strip multi-spaces

        return $str;
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
