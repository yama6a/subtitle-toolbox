<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

class StringHelpers
{
    public const UNIX_LINE_ENDING    = "\n";
    public const MAC_LINE_ENDING     = "\r";
    public const WINDOWS_LINE_ENDING = "\r\n";

    private const UTF8_BOM = "\xEF\xBB\xBF";

    // UTF-32 LE comes before UTF-16 LE because their BOMs share the first two bytes.
    private const UNICODE_BOMS = [
        "\x00\x00\xFE\xFF" => "UTF-32BE",
        "\xFF\xFE\x00\x00" => "UTF-32LE",
        "\xFE\xFF"         => "UTF-16BE",
        "\xFF\xFE"         => "UTF-16LE",
    ];


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


    public static function isValidUtf8(string $str): bool
    {
        return preg_match('//u', $str) === 1;
    }


    /**
     * Converts $str to UTF-8 from the encoding that its BOM names, or else from $sourceEncoding when it is not null.
     */
    public static function convertToUtf8(string $str, ?string $sourceEncoding = null): string
    {
        if (self::hasUtf8Bom($str)) {
            return $str;
        }

        foreach (self::UNICODE_BOMS as $bom => $encoding) {
            if (str_starts_with($str, $bom)) {
                return self::iconvToUtf8(substr($str, strlen($bom)), $encoding);
            }
        }

        if ($sourceEncoding === null || in_array(strtoupper($sourceEncoding), ["UTF-8", "UTF8"], true)) {
            return $str;
        }

        return self::iconvToUtf8($str, $sourceEncoding);
    }


    private static function iconvToUtf8(string $str, string $encoding): string
    {
        $converted = @iconv($encoding, "UTF-8", $str);
        if ($converted === false) {
            throw new ParsingException("Cannot convert the content from $encoding to UTF-8.");
        }

        return $converted;
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
        // CR CR LF comes from a CR LF file that went through a text-mode conversion a second time.
        return preg_replace('/\r+\n|\r/', static::UNIX_LINE_ENDING, $str);
    }


    /**
     * Returns the lowercase primary language subtag of a language code, for example "pt" for "pt_BR" and "" for null.
     */
    public static function primaryLanguage(?string $code): string
    {
        return strtolower(explode("-", str_replace("_", "-", $code ?? ""))[0]);
    }
}
