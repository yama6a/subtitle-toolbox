<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Character sets, preamble address codes and mid-row codes of CEA-608 line 21 captions.
 *
 * @see https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/CC_CHARS.HTML
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML
 *
 * @internal
 */
final class Cea608
{
    public const RESUME_CAPTION_LOADING   = 0x20;
    public const BACKSPACE                = 0x21;
    public const DELETE_TO_END_OF_ROW     = 0x24;
    public const ROLL_UP_2                = 0x25;
    public const ROLL_UP_3                = 0x26;
    public const ROLL_UP_4                = 0x27;
    public const RESUME_DIRECT_CAPTIONING = 0x29;
    public const TEXT_RESTART             = 0x2A;
    public const RESUME_TEXT_DISPLAY      = 0x2B;
    public const ERASE_DISPLAYED_MEMORY   = 0x2C;
    public const CARRIAGE_RETURN          = 0x2D;
    public const ERASE_NON_DISPLAYED      = 0x2E;
    public const END_OF_CAPTION           = 0x2F;

    // The first bytes of control codes on data channel 1. 0x11 also starts the special characters.
    public const FIRST_BYTE_MID_ROW    = 0x11;
    public const FIRST_BYTE_CONTROL    = 0x14;
    public const FIRST_BYTE_TAB_OFFSET = 0x17;

    // A tab offset moves the cursor right by its second byte minus 0x20: 1, 2 or 3 columns.
    public const TAB_OFFSET_BASE = 0x20;

    public const ROWS    = 15;
    public const COLUMNS = 32;

    // A caption shows at most 4 rows at once.
    public const MAX_LINES = 4;

    // Preamble address codes set the column in steps of 4.
    public const PAC_INDENT_STEP = 4;

    // The style index 7 of preamble address codes and mid-row codes means italics, not a color.
    public const STYLE_ITALIC = 7;

    /** The colors of preamble address codes and mid-row codes by their 3-bit index. */
    public const COLORS = ["#ffffff", "#00ff00", "#0000ff", "#00ffff", "#ff0000", "#ffff00", "#ff00ff"];

    public const WHITE = 0;

    /** The characters of the standard set that differ from ASCII. */
    private const STANDARD_CHARACTERS = [
        0x2A => "á", 0x5C => "é", 0x5E => "í", 0x5F => "ó", 0x60 => "ú",
        0x7B => "ç", 0x7C => "\u{F7}", 0x7D => "Ñ", 0x7E => "ñ",
    ];

    /** Second bytes 0x30 to 0x3F after 0x11. 0x39 is the transparent space. */
    private const SPECIAL_CHARACTERS = [
        "\u{AE}", "\u{B0}", "\u{BD}", "\u{BF}", "\u{2122}", "\u{A2}", "\u{A3}", "\u{266A}",
        "à", " ", "è", "â", "ê", "î", "ô", "û",
    ];

    /** Second bytes 0x20 to 0x3F after 0x12 and after 0x13. */
    private const EXTENDED_CHARACTERS = [
        0x12 => [
            "Á", "É", "Ó", "Ú", "Ü", "ü", "\u{2018}", "\u{A1}", "*", "\u{2019}", "\u{2014}", "\u{A9}", "\u{2120}", "\u{2022}", "\u{201C}", "\u{201D}",
            "À", "Â", "Ç", "È", "Ê", "Ë", "ë", "Î", "Ï", "ï", "Ô", "Ù", "ù", "Û", "\u{AB}", "\u{BB}",
        ],
        0x13 => [
            "Ã", "ã", "Í", "Ì", "ì", "Ò", "ò", "Õ", "õ", "{", "}", "\\", "^", "_", "\u{A6}", "~",
            "Ä", "ä", "Ö", "ö", "ß", "\u{A5}", "\u{A4}", "|", "Å", "å", "Ø", "ø", "\u{250C}", "\u{2510}", "\u{2514}", "\u{2518}",
        ],
    ];

    /** The standard character that a decoder without extended characters keeps, from the CCD column of McPoodle's table. */
    private const EXTENDED_FALLBACKS = [
        0x12 => "AEOUUu'!#'-cs.\"\"AACEEEeIIiOUuU\"\"",
        0x13 => "AaIIiOoOo[]//---AaOosYC/AaOo++++",
    ];

    /** Replacements for characters that CEA-608 lacks: base letters without their accents, and ASCII punctuation. */
    private const TRANSLITERATIONS = [
        "A" => "\u{100}\u{102}\u{104}\u{1CD}", "a" => "\u{101}\u{103}\u{105}\u{1CE}",
        "C" => "\u{106}\u{108}\u{10A}\u{10C}", "c" => "\u{107}\u{109}\u{10B}\u{10D}",
        "D" => "\u{D0}\u{10E}\u{110}", "d" => "\u{F0}\u{10F}\u{111}",
        "E" => "\u{112}\u{114}\u{116}\u{118}\u{11A}", "e" => "\u{113}\u{115}\u{117}\u{119}\u{11B}",
        "G" => "\u{11C}\u{11E}\u{120}\u{122}", "g" => "\u{11D}\u{11F}\u{121}\u{123}",
        "H" => "\u{124}\u{126}", "h" => "\u{125}\u{127}",
        "I" => "\u{128}\u{12A}\u{12C}\u{12E}\u{130}", "i" => "\u{129}\u{12B}\u{12D}\u{12F}\u{131}",
        "J" => "\u{134}", "j" => "\u{135}",
        "K" => "\u{136}", "k" => "\u{137}",
        "L" => "\u{139}\u{13B}\u{13D}\u{13F}\u{141}", "l" => "\u{13A}\u{13C}\u{13E}\u{140}\u{142}",
        "N" => "\u{143}\u{145}\u{147}", "n" => "\u{144}\u{146}\u{148}",
        "O" => "\u{14C}\u{14E}\u{150}", "o" => "\u{14D}\u{14F}\u{151}",
        "R" => "\u{154}\u{156}\u{158}", "r" => "\u{155}\u{157}\u{159}",
        "S" => "\u{15A}\u{15C}\u{15E}\u{160}\u{218}", "s" => "\u{15B}\u{15D}\u{15F}\u{161}\u{219}",
        "T" => "\u{162}\u{164}\u{166}\u{21A}", "t" => "\u{163}\u{165}\u{167}\u{21B}",
        "U" => "\u{168}\u{16A}\u{16C}\u{16E}\u{170}\u{172}", "u" => "\u{169}\u{16B}\u{16D}\u{16F}\u{171}\u{173}",
        "W" => "\u{174}", "w" => "\u{175}",
        "Y" => "\u{DD}\u{176}\u{178}", "y" => "\u{FD}\u{FF}\u{177}",
        "Z" => "\u{179}\u{17B}\u{17D}", "z" => "\u{17A}\u{17C}\u{17E}",
        "AE" => "\u{C6}", "ae" => "\u{E6}", "OE" => "\u{152}", "oe" => "\u{153}", "IJ" => "\u{132}", "ij" => "\u{133}",
        "TH" => "\u{DE}", "th" => "\u{FE}",
        "-" => "\u{2010}\u{2011}\u{2012}\u{2013}\u{2015}\u{2212}",
        "'" => "`\u{B4}\u{201A}\u{2032}", "\"" => "\u{201E}\u{2033}",
        "<" => "\u{2039}", ">" => "\u{203A}", "..." => "\u{2026}", "." => "\u{B7}", "x" => "\u{D7}", "EUR" => "\u{20AC}",
    ];

    /** @var ?array<string, string> */
    private static ?array $transliterations = null;

    /** The row of a preamble address code by the low three bits of the first byte and bit 0x20 of the second byte. */
    private const PAC_ROWS = [
        0x00 => [11, null], 0x01 => [1, 2], 0x02 => [3, 4], 0x03 => [12, 13],
        0x04 => [14, 15], 0x05 => [5, 6], 0x06 => [7, 8], 0x07 => [9, 10],
    ];

    /** @var ?array<string, array{byte?: int, pair?: array{int, int}}> */
    private static ?array $characterCodes = null;


    public static function hasOddParity(int $byte): bool
    {
        $bits = 0;
        for ($value = $byte & 0xFF; $value > 0; $value >>= 1) {
            $bits += $value & 1;
        }

        return $bits % 2 === 1;
    }


    /**
     * Sets bit 7 of a 7-bit value so that the byte has odd parity.
     */
    public static function withParity(int $value): int
    {
        $value &= 0x7F;

        return self::hasOddParity($value) ? $value : $value | 0x80;
    }


    /**
     * Returns the character of a 7-bit standard code from 0x20 to 0x7E, or null for other codes.
     */
    public static function standardCharacter(int $code): ?string
    {
        if ($code < 0x20 || $code > 0x7E) {
            return null;
        }

        return self::STANDARD_CHARACTERS[$code] ?? chr($code);
    }


    /**
     * Returns the special character of a second byte from 0x30 to 0x3F after 0x11, or null for other bytes.
     */
    public static function specialCharacter(int $secondByte): ?string
    {
        return self::SPECIAL_CHARACTERS[$secondByte - 0x30] ?? null;
    }


    /**
     * Returns the extended character of the first byte 0x12 or 0x13 and a second byte from 0x20 to 0x3F, or null.
     */
    public static function extendedCharacter(int $firstByte, int $secondByte): ?string
    {
        return self::EXTENDED_CHARACTERS[$firstByte][$secondByte - 0x20] ?? null;
    }


    /**
     * Returns the 7-bit codes of a character, or null when CEA-608 has no such character.
     * An extended character has a pair and the byte of the standard character that goes before the pair.
     *
     * @return array{byte?: int, pair?: array{int, int}}|null
     */
    public static function encodeCharacter(string $character): ?array
    {
        self::$characterCodes ??= self::buildCharacterCodes();

        return self::$characterCodes[$character] ?? null;
    }


    /**
     * Returns the CEA-608 characters that stand in for a character that CEA-608 lacks, or null when none do.
     */
    public static function transliterate(string $character): ?string
    {
        if (self::$transliterations === null) {
            self::$transliterations = [];
            foreach (self::TRANSLITERATIONS as $replacement => $characters) {
                foreach (mb_str_split($characters) as $from) {
                    self::$transliterations[$from] = (string)$replacement;
                }
            }
        }

        return self::$transliterations[$character] ?? null;
    }


    /**
     * Decodes a preamble address code, given without parity bits and with the first byte of data channel 1.
     *
     * @return array{row: int, column: int, color: int, italic: bool, underline: bool}|null
     */
    public static function decodePac(int $firstByte, int $secondByte): ?array
    {
        if ($firstByte < 0x10 || $firstByte > 0x17 || $secondByte < 0x40 || $secondByte > 0x7F) {
            return null;
        }

        $row = self::PAC_ROWS[$firstByte & 0x07][($secondByte & 0x20) === 0 ? 0 : 1];
        if ($row === null) {
            return null;
        }

        $attributes = $secondByte & 0x1F;
        $underline  = ($attributes & 0x01) === 1;
        if ($attributes >= 0x10) {
            return ["row" => $row, "column" => (($attributes & 0x0E) >> 1) * self::PAC_INDENT_STEP, "color" => self::WHITE, "italic" => false, "underline" => $underline];
        }

        $style = $attributes >> 1;

        return ["row" => $row, "column" => 0, "color" => $style === self::STYLE_ITALIC ? self::WHITE : $style, "italic" => $style === self::STYLE_ITALIC, "underline" => $underline];
    }


    /**
     * Returns the 7-bit bytes of the preamble address code for a row and a column.
     * The row is from 1 to 15. The column is from 0 to 28 in steps of 4.
     * Only column 0 can set a color other than white or italics.
     *
     * @return array{int, int}
     */
    public static function encodePac(int $row, int $column, int $color = self::WHITE, bool $italic = false, bool $underline = false): array
    {
        foreach (self::PAC_ROWS as $low => $rows) {
            $half = array_search($row, $rows, true);
            if ($half === false) {
                continue;
            }

            $attributes = match (true) {
                $italic                => 0x0E,
                $color !== self::WHITE => $color << 1,
                default                => 0x10 | (intdiv($column, self::PAC_INDENT_STEP) << 1),
            };

            return [0x10 | $low, 0x40 | ($half === 1 ? 0x20 : 0x00) | $attributes | ($underline ? 0x01 : 0x00)];
        }

        throw new InvalidArgumentException("CEA-608 has the rows 1 to 15, got $row.");
    }


    /**
     * Decodes the second byte of a mid-row code. The color is null for the italics code, which keeps the color.
     *
     * @return array{color: ?int, italic: bool, underline: bool}
     */
    public static function decodeMidRow(int $secondByte): array
    {
        $style = ($secondByte & 0x0E) >> 1;

        return [
            "color"     => $style === self::STYLE_ITALIC ? null : $style,
            "italic"    => $style === self::STYLE_ITALIC,
            "underline" => ($secondByte & 0x01) === 1,
        ];
    }


    /**
     * Returns the second byte of the mid-row code for a color, or for italics when $color is null.
     */
    public static function encodeMidRow(?int $color, bool $underline): int
    {
        return 0x20 | (($color ?? self::STYLE_ITALIC) << 1) | ($underline ? 0x01 : 0x00);
    }


    /**
     * @return array<string, array{byte?: int, pair?: array{int, int}}>
     */
    private static function buildCharacterCodes(): array
    {
        $codes = [];
        foreach (self::EXTENDED_CHARACTERS as $firstByte => $characters) {
            foreach ($characters as $index => $character) {
                $codes[$character] = ["byte" => ord(self::EXTENDED_FALLBACKS[$firstByte][$index]), "pair" => [$firstByte, 0x20 + $index]];
            }
        }
        foreach (self::SPECIAL_CHARACTERS as $index => $character) {
            $codes[$character] = ["pair" => [self::FIRST_BYTE_MID_ROW, 0x30 + $index]];
        }
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $codes[self::standardCharacter($code)] = ["byte" => $code];
        }

        return $codes;
    }
}
