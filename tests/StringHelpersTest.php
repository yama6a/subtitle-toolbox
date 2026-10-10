<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class StringHelpersTest extends TestCase
{
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


    public function testConvertToUtf8KeepsValidUtf8WithAnotherSourceEncoding(): void
    {
        $this->assertSame("مرحبا", StringHelpers::convertToUtf8("مرحبا", "Windows-1256"));
        $this->assertSame("Café", StringHelpers::convertToUtf8("Café", TextEncoding::Windows1252));
        $this->assertSame("مرحبا", StringHelpers::convertToUtf8(iconv("UTF-8", "Windows-1256", "مرحبا"), "Windows-1256"));
    }


    public function testConvertToUtf8ConvertsUtf16AndUtf32WithoutBomFromAsciiText(): void
    {
        foreach (["UTF-16LE", "UTF-16BE", "UTF-32LE"] as $encoding) {
            $this->assertSame("Hello", StringHelpers::convertToUtf8(iconv("UTF-8", $encoding, "Hello"), $encoding), $encoding);
        }
    }


    public function testConvertToUtf8ReadsUtf16WithoutBom(): void
    {
        foreach (["UTF-16LE", "UTF-16BE"] as $encoding) {
            $this->assertSame("Grüße", StringHelpers::convertToUtf8(iconv("UTF-8", $encoding, "Grüße")), $encoding);
            $this->assertSame("Grüße", StringHelpers::convertToUtf8(iconv("UTF-8", $encoding, "Grüße"), "Windows-1252"), $encoding);
        }
    }


    public function testConvertToUtf8DoesNotTakeAStrayZeroByteForUtf16(): void
    {
        $this->assertSame("Caf\xE9\x00 au lait", StringHelpers::convertToUtf8("Caf\xE9\x00 au lait"));
        $this->assertSame("Café\x00 ok", StringHelpers::convertToUtf8("Café\x00 ok"));
        $this->assertSame("A\x00\x00\x00B\x00\x00\x00", StringHelpers::convertToUtf8("A\x00\x00\x00B\x00\x00\x00"));
    }


    public function testConvertToUtf8DetectsTheCodePageWithoutBomAndSourceEncoding(): void
    {
        $this->assertSame("Café", StringHelpers::convertToUtf8("Caf\xE9"));
        $this->assertSame("Caf\xE9", StringHelpers::convertToUtf8("Caf\xE9", "utf-8"));
        $this->assertSame("Caf\xE9\x00", StringHelpers::convertToUtf8("Caf\xE9\x00"));
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


    public function testInvalidUtf8BytesAreFoundAndReplaced(): void
    {
        $this->assertNull(StringHelpers::findInvalidUtf8Offset("Café \u{1F600}"));
        $this->assertSame(3, StringHelpers::findInvalidUtf8Offset("Caf\xE9 \xC3\xA9"));
        $this->assertSame(1, StringHelpers::findInvalidUtf8Offset("a\xC0\xAFb"));
        $this->assertSame(1, StringHelpers::findInvalidUtf8Offset("a\xED\xA0\x80b"));
        $this->assertSame("Caf\u{FFFD} é", StringHelpers::replaceInvalidUtf8("Caf\xE9 \xC3\xA9"));
        $this->assertSame("a\u{FFFD}\u{FFFD}b", StringHelpers::replaceInvalidUtf8("a\xE2\x82b"));
        $this->assertSame("Café", StringHelpers::replaceInvalidUtf8("Café"));
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
