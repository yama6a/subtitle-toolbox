<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;

class WebVttFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"), WebVttParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->format(WebVttFormatter::class)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/strip_xml.vtt"), WebVttParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->format(WebVttFormatter::class)
        );
    }
}
