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
}
