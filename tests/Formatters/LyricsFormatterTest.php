<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Subtitle;

class LyricsFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectly()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"), LyricsParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }


    public function testUnsupportedXmlTagsAreStrippedAway()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), LyricsParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }
}
