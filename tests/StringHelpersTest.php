<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

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
}
