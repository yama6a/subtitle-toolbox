<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

class SubRipFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/strip_xml.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testAllXmlTagsAreStrippedAwayIfOptionIsSet()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/strip_xml.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/all_tags_stripped.srt"),
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS])
        );
    }
}
