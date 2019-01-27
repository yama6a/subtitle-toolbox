<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Subtitle;

class LyricsParserTest extends TestCase
{
    public function testValidLrcFileParses()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"), LyricsParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }


    public function testStripsIdTags()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), LyricsParser::class
        );

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }


    public function testExceededMinutesIgnoredCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_minutes.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Second Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededSecondsIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_seconds.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededMilliSecondAccuracyIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_centi_accuracy.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTextIgnoresCue()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), LyricsParser::class);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTimestampIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/missing_timestamps.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }
}
