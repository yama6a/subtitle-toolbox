<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Encoding\DecodedText;
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
     * Without a BOM, content with a zero byte in most even or most odd positions is read as UTF-16, unless
     * $sourceEncoding names UTF-16 or UTF-32.
     *
     * @param TextEncoding|string|null $sourceEncoding A TextEncoding case, or any other name that iconv accepts, for example "CP1125".
     */
    public static function convertToUtf8(string $str, TextEncoding|string|null $sourceEncoding = null): string
    {
        return self::decode($str, $sourceEncoding)->content;
    }


    /**
     * Converts $str to UTF-8 as convertToUtf8() does, and returns the encoding that it read.
     *
     * @internal
     */
    public static function decode(string $str, TextEncoding|string|null $sourceEncoding = null): DecodedText
    {
        $sourceEncoding = $sourceEncoding instanceof TextEncoding ? $sourceEncoding->value : $sourceEncoding;

        if (self::hasUtf8Bom($str)) {
            return new DecodedText($str, "UTF-8");
        }

        foreach (self::UNICODE_BOMS as $bom => $encoding) {
            if (str_starts_with($str, $bom)) {
                return new DecodedText(self::iconvToUtf8(substr($str, strlen($bom)), $encoding), $encoding);
            }
        }

        $isWide = $sourceEncoding !== null && preg_match('/\A(?:UTF-?(?:16|32)|UCS-?[24])/i', $sourceEncoding) === 1;
        $utf16  = $isWide ? null : self::detectUtf16($str);
        if ($utf16 !== null) {
            return new DecodedText(self::iconvToUtf8($str, $utf16), $utf16, "The content is $utf16 without a BOM.");
        }

        if ($sourceEncoding === null || in_array(strtoupper($sourceEncoding), ["UTF-8", "UTF8"], true)) {
            return new DecodedText($str, "UTF-8");
        }

        // Zero bytes mark UTF-16 or UTF-32 without a BOM, whose ASCII text is also valid UTF-8.
        if (self::isValidUtf8($str) && !str_contains($str, "\0")) {
            self::iconvToUtf8("", $sourceEncoding);

            return new DecodedText($str, "UTF-8");
        }

        return new DecodedText(self::iconvToUtf8($str, $sourceEncoding), $sourceEncoding);
    }


    /**
     * Returns UTF-16LE or UTF-16BE when at least 40 % of the first 512 byte pairs have a zero byte on one side and
     * at most 5 % on the other. ASCII text in UTF-16 has a zero byte in each pair. UTF-32 has zero bytes on both sides.
     */
    private static function detectUtf16(string $str): ?string
    {
        $sample = substr($str, 0, 1024);
        $pairs  = intdiv(strlen($sample), 2);
        if ($pairs === 0 || strlen($str) % 2 !== 0) {
            return null;
        }

        $zeros = [0, 0];
        for ($i = 0; $i < $pairs * 2; $i++) {
            if ($sample[$i] === "\0") {
                $zeros[$i % 2]++;
            }
        }

        return match (true) {
            $zeros[1] >= 0.4 * $pairs && $zeros[0] <= 0.05 * $pairs => "UTF-16LE",
            $zeros[0] >= 0.4 * $pairs && $zeros[1] <= 0.05 * $pairs => "UTF-16BE",
            default                                                  => null,
        };
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
