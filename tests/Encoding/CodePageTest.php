<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodePageTest extends TestCase
{
    public static function pairs(): array
    {
        return [
            "ISO 8859-5 Cyrillic" => [CodePage::ISO_8859_5, "\xBF\xDE\xD5\xD7\xD4 \xF0", "Поезд №"],
            "ISO 8859-6 Arabic"   => [CodePage::ISO_8859_6, "\xC7\xE4\xCE\xC8\xD2\xAC \xBF", "الخبز، ؟"],
            "ISO 8859-7 Greek"    => [CodePage::ISO_8859_7, "\xC1\xFD\xF1\xE9\xEF", "Αύριο"],
            "ISO 8859-8 Hebrew"   => [CodePage::ISO_8859_8, "\xF9\xEC\xE5\xED", "שלום"],
            "code page 850"       => [CodePage::CP_850, "M\x82t\x82o \x9C", "Météo £"],
            "code page 437"       => [CodePage::CP_437, "\x81ber \xE1", "über ß"],
        ];
    }


    #[DataProvider("pairs")]
    public function testDecodes(array $table, string $bytes, string $text): void
    {
        $this->assertSame($text, CodePage::decode($bytes, $table));
    }


    #[DataProvider("pairs")]
    public function testEncodes(array $table, string $bytes, string $text): void
    {
        $this->assertSame(bin2hex($bytes), bin2hex(CodePage::encode($text, $table)));
    }


    public function testDropsBytesThatThe1987EditionLeavesUndefined(): void
    {
        $this->assertSame("ab", CodePage::decode("a\xA4\xA5\xAAb", CodePage::ISO_8859_7));
        $this->assertSame("ab", CodePage::decode("a\xFD\xFEb", CodePage::ISO_8859_8));
    }


    public function testEncodesCharactersOutsideTheTableAsQuestionMark(): void
    {
        $this->assertSame("a?b", CodePage::encode("a\u{20AC}b", CodePage::ISO_8859_7));
    }
}
