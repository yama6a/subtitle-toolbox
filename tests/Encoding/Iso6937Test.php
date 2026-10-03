<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Iso6937Test extends TestCase
{
    public static function pairs(): array
    {
        return [
            "accent before the letter"  => ["Caf\xC2e", "Café"],
            "EBU Tech 3264 examples"    => ["\xC8A \xC3e", "Ä ê"],
            "letters of column E and F" => ["\xE1\xF9\xFB\xF8", "Æøßł"],
            "dollar and currency sign"  => ["\xA4 \x24", "\$ \u{00A4}"],
            "quotes"                    => ["\xA9a\xB9 \xAAb\xBA", "\u{2018}a\u{2019} \u{201C}b\u{201D}"],
            "caron and ogonek"          => ["\xCFs\xCEa", "šą"],
            "plain ASCII"               => ["Hello, world! #1", "Hello, world! #1"],
        ];
    }


    #[DataProvider("pairs")]
    public function testDecodes(string $bytes, string $text): void
    {
        $this->assertSame($text, Iso6937::decode($bytes));
    }


    #[DataProvider("pairs")]
    public function testEncodes(string $bytes, string $text): void
    {
        $this->assertSame(bin2hex($bytes), bin2hex(Iso6937::encode($text)));
    }


    public function testDecodesALetterWithoutPrecomposedFormAsCombiningSequence(): void
    {
        $this->assertSame("B\u{0301}", Iso6937::decode("\xC2B"));
        $this->assertSame("\xC2B", Iso6937::encode("B\u{0301}"));
    }


    public function testDropsUndefinedBytesAndADiacriticWithoutLetter(): void
    {
        $this->assertSame("ab", Iso6937::decode("a\xA6\xA8\xC9\xE5b\xC2"));
    }


    public function testEncodesCharactersOutsideTheTableAsQuestionMark(): void
    {
        $this->assertSame("? ? ?", Iso6937::encode("\u{20AC} \u{4E2D} \u{0301}"));
    }
}
