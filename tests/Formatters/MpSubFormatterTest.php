<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

class MpSubFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectlyAndXmlTagsAreStripped()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid.mpsub"),
            $subtitle->format(MpSubFormatter::class)
        );
    }
}
