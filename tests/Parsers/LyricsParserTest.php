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

        $this->assertSame("First Text", $subtitle->getCues()->first()->getLines()->first());
        $this->assertSame("Second Text", $subtitle->getCues()->first()->getLines()->get(1));
        $this->assertSame(2, $subtitle->getCues()->count());
    }


    public function testExceededSecondsIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_seconds.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()->first()->getLines()->first());
        $this->assertSame("Third Text", $subtitle->getCues()->get(1)->getLines()->first());
        $this->assertSame(2, $subtitle->getCues()->count());
    }


    public function testExceededMilliSecondAccuracyIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_centi_accuracy.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()->first()->getLines()->first());
        $this->assertSame("Third Text", $subtitle->getCues()->first()->getLines()->get(1));
        $this->assertSame(2, $subtitle->getCues()->count());
    }


    public function testMissingTextIgnoresCue()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), LyricsParser::class);

        $this->assertSame("First Text", $subtitle->getCues()->first()->getLines()->first());
        $this->assertSame("Third Text", $subtitle->getCues()->first()->getLines()->get(1));
        $this->assertSame(2, $subtitle->getCues()->count());
    }


    public function testMissingTimestampIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/missing_timestamps.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()->first()->getLines()->first());
        $this->assertSame("Third Text", $subtitle->getCues()->first()->getLines()->get(1));
        $this->assertSame(2, $subtitle->getCues()->count());
    }
}
