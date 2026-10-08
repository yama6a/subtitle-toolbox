<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

final class StringHelpers
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    // UTF-32 LE comes before UTF-16 LE because their BOMs share the first two bytes.
    private const UNICODE_BOMS = [
        "\x00\x00\xFE\xFF" => "UTF-32BE",
        "\xFF\xFE\x00\x00" => "UTF-32LE",
        "\xFE\xFF"         => "UTF-16BE",
        "\xFF\xFE"         => "UTF-16LE",
    ];


    /** @internal */
    public static function hasUtf8Bom(string $str): bool
    {
        return str_starts_with($str, self::UTF8_BOM);
    }


    /** @internal */
    public static function removeUtf8Bom(string $str): string
    {
        return self::hasUtf8Bom($str) ? substr($str, strlen(self::UTF8_BOM)) : $str;
    }


    /** @internal */
    public static function addUtf8Bom(string $str): string
    {
        return self::hasUtf8Bom($str) ? $str : self::UTF8_BOM . $str;
    }


    public static function isValidUtf8(string $str): bool
    {
        return preg_match('//u', $str) === 1;
    }


    /**
     * Returns true when ext-mbstring is loaded and $str is valid UTF-8. mbstring is not part of a default PHP build.
     *
     * @internal
     */
    public static function canUseMultibyte(string $str): bool
    {
        return extension_loaded("mbstring") && self::isValidUtf8($str);
    }


    /**
     * Converts $str to UTF-8 from the encoding that its BOM names, or else from $sourceEncoding when it is not null.
     * Without a BOM, $str stays unchanged when it is valid UTF-8 and holds no zero bytes.
     *
     * @param TextEncoding|string|null $sourceEncoding A TextEncoding case, or any other name that iconv accepts, for example "CP1125".
     */
    public static function convertToUtf8(string $str, TextEncoding|string|null $sourceEncoding = null): string
    {
        $sourceEncoding = $sourceEncoding instanceof TextEncoding ? $sourceEncoding->value : $sourceEncoding;

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

        // Zero bytes mark UTF-16 or UTF-32 without a BOM, whose ASCII text is also valid UTF-8.
        if (self::isValidUtf8($str) && !str_contains($str, "\0")) {
            self::iconvToUtf8("", $sourceEncoding);

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


    /** @internal */
    public static function cleanString(string $str): string
    {
        $str = self::normalizeEOLs($str);
        $str = self::normalizeSpaces($str);
        $str = self::removeEmptyLines($str);

        return trim($str);
    }


    /** @internal */
    public static function trimEachLine(string $str): string
    {
        $lines = explode(LineEnding::Lf->value, $str);
        $lines = array_map("trim", $lines);

        return implode(LineEnding::Lf->value, $lines);
    }


    /** @internal */
    public static function removeEmptyLines(string $str): string
    {
        return preg_replace('/\n+/', LineEnding::Lf->value, $str);
    }


    /** @internal */
    public static function normalizeSpaces(string $str): string
    {
        $str = preg_replace('/\t+/', ' ', $str);
        $str = preg_replace('/ +/', ' ', $str);

        return $str;
    }


    /** @internal */
    public static function normalizeEOLs(string $str): string
    {
        // CR CR LF comes from a CR LF file that went through a text-mode conversion a second time.
        return preg_replace('/\r+\n|\r/', LineEnding::Lf->value, $str);
    }


    /**
     * Returns the lowercase primary language subtag of a language code, for example "pt" for "pt_BR" and "" for null.
     *
     * @internal
     */
    public static function primaryLanguage(?string $code): string
    {
        return strtolower(explode("-", str_replace("_", "-", $code ?? ""))[0]);
    }
}
