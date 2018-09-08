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
}
