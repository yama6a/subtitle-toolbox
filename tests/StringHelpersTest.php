<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class StringHelpersTest extends TestCase
{
    public function testRemoveDoubleEmptyLinesKeepsOneEmptyLine(): void
    {
        $this->assertSame("a\n\nb", StringHelpers::removeDoubleEmptyLines("a\n\n\n\nb"));
        $this->assertSame("a\n\nb", StringHelpers::removeDoubleEmptyLines("a\n\nb"));
    }


    public function testUtf8BomIsDetectedAddedOnceAndRemoved(): void
    {
        $withBom = "\xEF\xBB\xBFtext";

        $this->assertTrue(StringHelpers::hasUtf8Bom($withBom));
        $this->assertFalse(StringHelpers::hasUtf8Bom("text"));
        $this->assertFalse(StringHelpers::hasUtf8Bom(""));
        $this->assertSame($withBom, StringHelpers::addUtf8Bom("text"));
        $this->assertSame($withBom, StringHelpers::addUtf8Bom($withBom));
        $this->assertSame("text", StringHelpers::removeUtf8Bom($withBom));
        $this->assertSame("text", StringHelpers::removeUtf8Bom("text"));
    }


    public function testNormalizeEolsTurnsEveryLineEndingIntoOneLineFeed(): void
    {
        $this->assertSame("a\nb\nc\nd", StringHelpers::normalizeEOLs("a\r\nb\rc\nd"));
        $this->assertSame("a\n\nb", StringHelpers::normalizeEOLs("a\r\rb"));
        $this->assertSame("a\nb\n\nc", StringHelpers::normalizeEOLs("a\r\r\nb\r\r\n\r\r\nc"));
    }


    public function testIsValidUtf8(): void
    {
        $this->assertTrue(StringHelpers::isValidUtf8("Caf\xC3\xA9"));
        $this->assertTrue(StringHelpers::isValidUtf8(""));
        $this->assertFalse(StringHelpers::isValidUtf8("Caf\xE9"));
        $this->assertFalse(StringHelpers::isValidUtf8("\xFF\xFEC\x00"));
    }


    public function testConvertToUtf8ReadsEveryUnicodeBomAndDropsIt(): void
    {
        $this->assertSame("Café", StringHelpers::convertToUtf8("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", "Café")));
        $this->assertSame("Café", StringHelpers::convertToUtf8("\xFE\xFF" . iconv("UTF-8", "UTF-16BE", "Café")));
        $this->assertSame("Café", StringHelpers::convertToUtf8("\xFF\xFE\x00\x00" . iconv("UTF-8", "UTF-32LE", "Café")));
        $this->assertSame("Café", StringHelpers::convertToUtf8("\x00\x00\xFE\xFF" . iconv("UTF-8", "UTF-32BE", "Café")));
    }


    public function testConvertToUtf8UsesTheSourceEncodingOnlyWithoutBom(): void
    {
        $this->assertSame("Café", StringHelpers::convertToUtf8("Caf\xE9", "Windows-1252"));
        $this->assertSame("Café", StringHelpers::convertToUtf8("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", "Café"), "Windows-1252"));
        $this->assertSame("\xEF\xBB\xBFCafé", StringHelpers::convertToUtf8("\xEF\xBB\xBFCafé", "Windows-1252"));
    }


    public function testConvertToUtf8KeepsTheBytesWithoutBomAndSourceEncoding(): void
    {
        $this->assertSame("Caf\xE9", StringHelpers::convertToUtf8("Caf\xE9"));
        $this->assertSame("Caf\xE9", StringHelpers::convertToUtf8("Caf\xE9", "utf-8"));
    }


    public function testConvertToUtf8ThrowsForAnUnknownEncoding(): void
    {
        $this->expectException(ParsingException::class);

        StringHelpers::convertToUtf8("Café", "No-Such-Encoding");
    }


    public function testConvertToUtf8ThrowsForBytesThatAreInvalidInTheSourceEncoding(): void
    {
        $this->expectException(ParsingException::class);

        StringHelpers::convertToUtf8("\xFF\xFEC\x00a");
    }


    public function testPrimaryLanguageReturnsTheLowercaseFirstSubtag(): void
    {
        $this->assertSame("pt", StringHelpers::primaryLanguage("pt_BR"));
        $this->assertSame("tr", StringHelpers::primaryLanguage("TR-tr"));
        $this->assertSame("deu", StringHelpers::primaryLanguage("deu"));
        $this->assertSame("", StringHelpers::primaryLanguage(null));
    }


    public function testCanUseMultibyteRejectsInvalidUtf8(): void
    {
        $this->assertSame(extension_loaded("mbstring"), StringHelpers::canUseMultibyte("Grüße"));
        $this->assertFalse(StringHelpers::canUseMultibyte("Gr\xFC\xDFe"));
    }
}
