<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use SubtitleToolbox\Markup;

/**
 * ISO 6937 as character code table 00 of EBU Tech 3264, appendix 2. PHP has no converter for it.
 */
class Iso6937
{
    // The characters that differ from ASCII. 0x24 is the currency sign and 0xA4 is the dollar sign.
    public const CHARACTERS = [
        0x24 => "\u{00A4}", 0xA0 => "\u{00A0}", 0xA1 => "\u{00A1}", 0xA2 => "\u{00A2}",
        0xA3 => "\u{00A3}", 0xA4 => "$", 0xA5 => "\u{00A5}", 0xA7 => "\u{00A7}",
        0xA9 => "\u{2018}", 0xAA => "\u{201C}", 0xAB => "\u{00AB}", 0xAC => "\u{2190}",
        0xAD => "\u{2191}", 0xAE => "\u{2192}", 0xAF => "\u{2193}", 0xB0 => "\u{00B0}",
        0xB1 => "\u{00B1}", 0xB2 => "\u{00B2}", 0xB3 => "\u{00B3}", 0xB4 => "\u{00D7}",
        0xB5 => "\u{00B5}", 0xB6 => "\u{00B6}", 0xB7 => "\u{00B7}", 0xB8 => "\u{00F7}",
        0xB9 => "\u{2019}", 0xBA => "\u{201D}", 0xBB => "\u{00BB}", 0xBC => "\u{00BC}",
        0xBD => "\u{00BD}", 0xBE => "\u{00BE}", 0xBF => "\u{00BF}", 0xD0 => "\u{2015}",
        0xD1 => "\u{00B9}", 0xD2 => "\u{00AE}", 0xD3 => "\u{00A9}", 0xD4 => "\u{2122}",
        0xD5 => "\u{266A}", 0xD6 => "\u{00AC}", 0xD7 => "\u{00A6}", 0xDC => "\u{215B}",
        0xDD => "\u{215C}", 0xDE => "\u{215D}", 0xDF => "\u{215E}", 0xE0 => "\u{2126}",
        0xE1 => "\u{00C6}", 0xE2 => "\u{0110}", 0xE3 => "\u{00AA}", 0xE4 => "\u{0126}",
        0xE6 => "\u{0132}", 0xE7 => "\u{013F}", 0xE8 => "\u{0141}", 0xE9 => "\u{00D8}",
        0xEA => "\u{0152}", 0xEB => "\u{00BA}", 0xEC => "\u{00DE}", 0xED => "\u{0166}",
        0xEE => "\u{014A}", 0xEF => "\u{0149}", 0xF0 => "\u{0138}", 0xF1 => "\u{00E6}",
        0xF2 => "\u{0111}", 0xF3 => "\u{00F0}", 0xF4 => "\u{0127}", 0xF5 => "\u{0131}",
        0xF6 => "\u{0133}", 0xF7 => "\u{0140}", 0xF8 => "\u{0142}", 0xF9 => "\u{00F8}",
        0xFA => "\u{0153}", 0xFB => "\u{00DF}", 0xFC => "\u{00FE}", 0xFD => "\u{0167}",
        0xFE => "\u{014B}", 0xFF => "\u{00AD}",
    ];

    // The non-spacing diacritical marks of column C. Each comes before its letter, EBU Tech 3264 section 5.
    public const DIACRITICS = [
        0xC1 => "\u{0300}", 0xC2 => "\u{0301}", 0xC3 => "\u{0302}", 0xC4 => "\u{0303}",
        0xC5 => "\u{0304}", 0xC6 => "\u{0306}", 0xC7 => "\u{0307}", 0xC8 => "\u{0308}",
        0xCA => "\u{030A}", 0xCB => "\u{0327}", 0xCC => "\u{0332}", 0xCD => "\u{030B}",
        0xCE => "\u{0328}", 0xCF => "\u{030C}",
    ];

    // The precomposed Unicode letters, as the diacritic byte and the base letter.
    public const COMPOSED = [
        "\u{00C0}" => [0xC1, "A"], "\u{00C8}" => [0xC1, "E"], "\u{00CC}" => [0xC1, "I"], "\u{01F8}" => [0xC1, "N"],
        "\u{00D2}" => [0xC1, "O"], "\u{00D9}" => [0xC1, "U"], "\u{1E80}" => [0xC1, "W"], "\u{1EF2}" => [0xC1, "Y"],
        "\u{00E0}" => [0xC1, "a"], "\u{00E8}" => [0xC1, "e"], "\u{00EC}" => [0xC1, "i"], "\u{01F9}" => [0xC1, "n"],
        "\u{00F2}" => [0xC1, "o"], "\u{00F9}" => [0xC1, "u"], "\u{1E81}" => [0xC1, "w"], "\u{1EF3}" => [0xC1, "y"],
        "\u{00C1}" => [0xC2, "A"], "\u{0106}" => [0xC2, "C"], "\u{00C9}" => [0xC2, "E"], "\u{01F4}" => [0xC2, "G"],
        "\u{00CD}" => [0xC2, "I"], "\u{1E30}" => [0xC2, "K"], "\u{0139}" => [0xC2, "L"], "\u{1E3E}" => [0xC2, "M"],
        "\u{0143}" => [0xC2, "N"], "\u{00D3}" => [0xC2, "O"], "\u{1E54}" => [0xC2, "P"], "\u{0154}" => [0xC2, "R"],
        "\u{015A}" => [0xC2, "S"], "\u{00DA}" => [0xC2, "U"], "\u{1E82}" => [0xC2, "W"], "\u{00DD}" => [0xC2, "Y"],
        "\u{0179}" => [0xC2, "Z"], "\u{00E1}" => [0xC2, "a"], "\u{0107}" => [0xC2, "c"], "\u{00E9}" => [0xC2, "e"],
        "\u{01F5}" => [0xC2, "g"], "\u{00ED}" => [0xC2, "i"], "\u{1E31}" => [0xC2, "k"], "\u{013A}" => [0xC2, "l"],
        "\u{1E3F}" => [0xC2, "m"], "\u{0144}" => [0xC2, "n"], "\u{00F3}" => [0xC2, "o"], "\u{1E55}" => [0xC2, "p"],
        "\u{0155}" => [0xC2, "r"], "\u{015B}" => [0xC2, "s"], "\u{00FA}" => [0xC2, "u"], "\u{1E83}" => [0xC2, "w"],
        "\u{00FD}" => [0xC2, "y"], "\u{017A}" => [0xC2, "z"], "\u{00C2}" => [0xC3, "A"], "\u{0108}" => [0xC3, "C"],
        "\u{00CA}" => [0xC3, "E"], "\u{011C}" => [0xC3, "G"], "\u{0124}" => [0xC3, "H"], "\u{00CE}" => [0xC3, "I"],
        "\u{0134}" => [0xC3, "J"], "\u{00D4}" => [0xC3, "O"], "\u{015C}" => [0xC3, "S"], "\u{00DB}" => [0xC3, "U"],
        "\u{0174}" => [0xC3, "W"], "\u{0176}" => [0xC3, "Y"], "\u{1E90}" => [0xC3, "Z"], "\u{00E2}" => [0xC3, "a"],
        "\u{0109}" => [0xC3, "c"], "\u{00EA}" => [0xC3, "e"], "\u{011D}" => [0xC3, "g"], "\u{0125}" => [0xC3, "h"],
        "\u{00EE}" => [0xC3, "i"], "\u{0135}" => [0xC3, "j"], "\u{00F4}" => [0xC3, "o"], "\u{015D}" => [0xC3, "s"],
        "\u{00FB}" => [0xC3, "u"], "\u{0175}" => [0xC3, "w"], "\u{0177}" => [0xC3, "y"], "\u{1E91}" => [0xC3, "z"],
        "\u{00C3}" => [0xC4, "A"], "\u{1EBC}" => [0xC4, "E"], "\u{0128}" => [0xC4, "I"], "\u{00D1}" => [0xC4, "N"],
        "\u{00D5}" => [0xC4, "O"], "\u{0168}" => [0xC4, "U"], "\u{1E7C}" => [0xC4, "V"], "\u{1EF8}" => [0xC4, "Y"],
        "\u{00E3}" => [0xC4, "a"], "\u{1EBD}" => [0xC4, "e"], "\u{0129}" => [0xC4, "i"], "\u{00F1}" => [0xC4, "n"],
        "\u{00F5}" => [0xC4, "o"], "\u{0169}" => [0xC4, "u"], "\u{1E7D}" => [0xC4, "v"], "\u{1EF9}" => [0xC4, "y"],
        "\u{0100}" => [0xC5, "A"], "\u{0112}" => [0xC5, "E"], "\u{1E20}" => [0xC5, "G"], "\u{012A}" => [0xC5, "I"],
        "\u{014C}" => [0xC5, "O"], "\u{016A}" => [0xC5, "U"], "\u{0232}" => [0xC5, "Y"], "\u{0101}" => [0xC5, "a"],
        "\u{0113}" => [0xC5, "e"], "\u{1E21}" => [0xC5, "g"], "\u{012B}" => [0xC5, "i"], "\u{014D}" => [0xC5, "o"],
        "\u{016B}" => [0xC5, "u"], "\u{0233}" => [0xC5, "y"], "\u{0102}" => [0xC6, "A"], "\u{0114}" => [0xC6, "E"],
        "\u{011E}" => [0xC6, "G"], "\u{012C}" => [0xC6, "I"], "\u{014E}" => [0xC6, "O"], "\u{016C}" => [0xC6, "U"],
        "\u{0103}" => [0xC6, "a"], "\u{0115}" => [0xC6, "e"], "\u{011F}" => [0xC6, "g"], "\u{012D}" => [0xC6, "i"],
        "\u{014F}" => [0xC6, "o"], "\u{016D}" => [0xC6, "u"], "\u{0226}" => [0xC7, "A"], "\u{1E02}" => [0xC7, "B"],
        "\u{010A}" => [0xC7, "C"], "\u{1E0A}" => [0xC7, "D"], "\u{0116}" => [0xC7, "E"], "\u{1E1E}" => [0xC7, "F"],
        "\u{0120}" => [0xC7, "G"], "\u{1E22}" => [0xC7, "H"], "\u{0130}" => [0xC7, "I"], "\u{1E40}" => [0xC7, "M"],
        "\u{1E44}" => [0xC7, "N"], "\u{022E}" => [0xC7, "O"], "\u{1E56}" => [0xC7, "P"], "\u{1E58}" => [0xC7, "R"],
        "\u{1E60}" => [0xC7, "S"], "\u{1E6A}" => [0xC7, "T"], "\u{1E86}" => [0xC7, "W"], "\u{1E8A}" => [0xC7, "X"],
        "\u{1E8E}" => [0xC7, "Y"], "\u{017B}" => [0xC7, "Z"], "\u{0227}" => [0xC7, "a"], "\u{1E03}" => [0xC7, "b"],
        "\u{010B}" => [0xC7, "c"], "\u{1E0B}" => [0xC7, "d"], "\u{0117}" => [0xC7, "e"], "\u{1E1F}" => [0xC7, "f"],
        "\u{0121}" => [0xC7, "g"], "\u{1E23}" => [0xC7, "h"], "\u{1E41}" => [0xC7, "m"], "\u{1E45}" => [0xC7, "n"],
        "\u{022F}" => [0xC7, "o"], "\u{1E57}" => [0xC7, "p"], "\u{1E59}" => [0xC7, "r"], "\u{1E61}" => [0xC7, "s"],
        "\u{1E6B}" => [0xC7, "t"], "\u{1E87}" => [0xC7, "w"], "\u{1E8B}" => [0xC7, "x"], "\u{1E8F}" => [0xC7, "y"],
        "\u{017C}" => [0xC7, "z"], "\u{00C4}" => [0xC8, "A"], "\u{00CB}" => [0xC8, "E"], "\u{1E26}" => [0xC8, "H"],
        "\u{00CF}" => [0xC8, "I"], "\u{00D6}" => [0xC8, "O"], "\u{00DC}" => [0xC8, "U"], "\u{1E84}" => [0xC8, "W"],
        "\u{1E8C}" => [0xC8, "X"], "\u{0178}" => [0xC8, "Y"], "\u{00E4}" => [0xC8, "a"], "\u{00EB}" => [0xC8, "e"],
        "\u{1E27}" => [0xC8, "h"], "\u{00EF}" => [0xC8, "i"], "\u{00F6}" => [0xC8, "o"], "\u{1E97}" => [0xC8, "t"],
        "\u{00FC}" => [0xC8, "u"], "\u{1E85}" => [0xC8, "w"], "\u{1E8D}" => [0xC8, "x"], "\u{00FF}" => [0xC8, "y"],
        "\u{00C5}" => [0xCA, "A"], "\u{016E}" => [0xCA, "U"], "\u{00E5}" => [0xCA, "a"], "\u{016F}" => [0xCA, "u"],
        "\u{1E98}" => [0xCA, "w"], "\u{1E99}" => [0xCA, "y"], "\u{00C7}" => [0xCB, "C"], "\u{1E10}" => [0xCB, "D"],
        "\u{0228}" => [0xCB, "E"], "\u{0122}" => [0xCB, "G"], "\u{1E28}" => [0xCB, "H"], "\u{0136}" => [0xCB, "K"],
        "\u{013B}" => [0xCB, "L"], "\u{0145}" => [0xCB, "N"], "\u{0156}" => [0xCB, "R"], "\u{015E}" => [0xCB, "S"],
        "\u{0162}" => [0xCB, "T"], "\u{00E7}" => [0xCB, "c"], "\u{1E11}" => [0xCB, "d"], "\u{0229}" => [0xCB, "e"],
        "\u{0123}" => [0xCB, "g"], "\u{1E29}" => [0xCB, "h"], "\u{0137}" => [0xCB, "k"], "\u{013C}" => [0xCB, "l"],
        "\u{0146}" => [0xCB, "n"], "\u{0157}" => [0xCB, "r"], "\u{015F}" => [0xCB, "s"], "\u{0163}" => [0xCB, "t"],
        "\u{0150}" => [0xCD, "O"], "\u{0170}" => [0xCD, "U"], "\u{0151}" => [0xCD, "o"], "\u{0171}" => [0xCD, "u"],
        "\u{0104}" => [0xCE, "A"], "\u{0118}" => [0xCE, "E"], "\u{012E}" => [0xCE, "I"], "\u{01EA}" => [0xCE, "O"],
        "\u{0172}" => [0xCE, "U"], "\u{0105}" => [0xCE, "a"], "\u{0119}" => [0xCE, "e"], "\u{012F}" => [0xCE, "i"],
        "\u{01EB}" => [0xCE, "o"], "\u{0173}" => [0xCE, "u"], "\u{01CD}" => [0xCF, "A"], "\u{010C}" => [0xCF, "C"],
        "\u{010E}" => [0xCF, "D"], "\u{011A}" => [0xCF, "E"], "\u{01E6}" => [0xCF, "G"], "\u{021E}" => [0xCF, "H"],
        "\u{01CF}" => [0xCF, "I"], "\u{01E8}" => [0xCF, "K"], "\u{013D}" => [0xCF, "L"], "\u{0147}" => [0xCF, "N"],
        "\u{01D1}" => [0xCF, "O"], "\u{0158}" => [0xCF, "R"], "\u{0160}" => [0xCF, "S"], "\u{0164}" => [0xCF, "T"],
        "\u{01D3}" => [0xCF, "U"], "\u{017D}" => [0xCF, "Z"], "\u{01CE}" => [0xCF, "a"], "\u{010D}" => [0xCF, "c"],
        "\u{010F}" => [0xCF, "d"], "\u{011B}" => [0xCF, "e"], "\u{01E7}" => [0xCF, "g"], "\u{021F}" => [0xCF, "h"],
        "\u{01D0}" => [0xCF, "i"], "\u{01F0}" => [0xCF, "j"], "\u{01E9}" => [0xCF, "k"], "\u{013E}" => [0xCF, "l"],
        "\u{0148}" => [0xCF, "n"], "\u{01D2}" => [0xCF, "o"], "\u{0159}" => [0xCF, "r"], "\u{0161}" => [0xCF, "s"],
        "\u{0165}" => [0xCF, "t"], "\u{01D4}" => [0xCF, "u"], "\u{017E}" => [0xCF, "z"],
    ];

    private const REPLACEMENT = "?";

    /** @var array<int, array<string, string>> diacritic byte => base letter => precomposed letter */
    private static array $compositions = [];


    /**
     * Decodes ISO 6937 bytes to UTF-8 and drops the bytes that the table leaves undefined.
     */
    public static function decode(string $bytes): string
    {
        $text      = "";
        $diacritic = null;
        foreach (str_split($bytes) as $byte) {
            $code = ord($byte);
            if (isset(self::DIACRITICS[$code])) {
                $diacritic = $code;
                continue;
            }

            $character = self::CHARACTERS[$code] ?? ($code >= 0x20 && $code < 0x7F ? $byte : null);
            if ($character !== null && $diacritic !== null) {
                $character = self::compositions()[$diacritic][$character] ?? $character . self::DIACRITICS[$diacritic];
            }

            $text      .= $character ?? "";
            $diacritic = null;
        }

        return $text;
    }


    /**
     * Encodes UTF-8 text to ISO 6937 and writes "?" for each character outside the table.
     */
    public static function encode(string $text): string
    {
        $bytes      = array_flip(self::CHARACTERS);
        $diacritics = array_flip(self::DIACRITICS);
        $characters = Markup::characters($text);

        $encoded       = "";
        $lastCharacter = "";
        foreach ($characters as $character) {
            if (isset($bytes[$character])) {
                $lastCharacter = chr($bytes[$character]);
            } elseif (strlen($character) === 1 && ord($character) >= 0x20 && ord($character) < 0x7F) {
                $lastCharacter = $character;
            } elseif (isset(self::COMPOSED[$character])) {
                $lastCharacter = chr(self::COMPOSED[$character][0]) . self::COMPOSED[$character][1];
            } elseif (isset($diacritics[$character]) && preg_match('/^[A-Za-z]$/', $lastCharacter)) {
                $encoded       = substr($encoded, 0, -1);
                $lastCharacter = chr($diacritics[$character]) . $lastCharacter;
            } else {
                $lastCharacter = self::REPLACEMENT;
            }

            $encoded .= $lastCharacter;
        }

        return $encoded;
    }


    /**
     * @return array<int, array<string, string>>
     */
    private static function compositions(): array
    {
        if (self::$compositions === []) {
            foreach (self::COMPOSED as $composed => [$diacritic, $letter]) {
                self::$compositions[$diacritic][$letter] = $composed;
            }
        }

        return self::$compositions;
    }
}
