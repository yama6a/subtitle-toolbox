<?php

declare(strict_types=1);

namespace SubtitleToolbox\Hls;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class TimestampMapTest extends TestCase
{
    public function testFromHeaderReadsTheRfcAttributeOrder(): void
    {
        $map = TimestampMap::fromHeader("X-TIMESTAMP-MAP=LOCAL:01:00:00.000,MPEGTS:324000000");

        $this->assertSame(324000000, $map->mpegts);
        $this->assertSame(3600.0, $map->local);
    }


    public function testFromHeaderReadsMpegtsFirstAndShortLocalTimes(): void
    {
        $map = TimestampMap::fromHeader(" X-TIMESTAMP-MAP=MPEGTS:181083, LOCAL:01:02.345 ");

        $this->assertSame(181083, $map->mpegts);
        $this->assertSame(62.345, $map->local);
    }


    public function testFromHeaderReadsHoursWithMoreThanTwoDigits(): void
    {
        $this->assertSame(360000.5, TimestampMap::fromHeader("X-TIMESTAMP-MAP=LOCAL:100:00:00.500,MPEGTS:0")->local);
    }


    public function testFromHeaderRejectsBrokenLines(): void
    {
        foreach (["WEBVTT", "X-TIMESTAMP-MAP=LOCAL:00:00:00.000", "X-TIMESTAMP-MAP=MPEGTS:900000,LOCAL:0:00.000",
                  "X-TIMESTAMP-MAP=MPEGTS:-1,LOCAL:00:00.000"] as $line) {
            try {
                TimestampMap::fromHeader($line);
                $this->fail("No exception for $line");
            } catch (ParsingException) {
                $this->addToAssertionCount(1);
            }
        }
    }


    public function testConstructorRejectsValuesOutsideTheRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimestampMap(TimestampMap::MPEGTS_WRAP);
    }


    public function testToHeaderWritesTheRfcAttributeOrder(): void
    {
        $this->assertSame("X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:900000", (new TimestampMap(900000))->toHeader());
        $this->assertSame("X-TIMESTAMP-MAP=LOCAL:01:01:01.001,MPEGTS:0", (new TimestampMap(0, 3661.001))->toHeader());
    }


    public function testFromSubtitleReadsTheHeaderFromTheFormatData(): void
    {
        $vtt = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/hls/shaka-vtt-071.vtt"), Format::WebVtt);

        $this->assertSame(["X-TIMESTAMP-MAP=LOCAL:01:00:00.000,MPEGTS:324000000"], $vtt->findFormatData("vtt")["headerLines"]);
        $this->assertSame(324000000, TimestampMap::fromSubtitle($vtt)->mpegts);
        $this->assertNull(TimestampMap::fromSubtitle(Subtitle::fromString("WEBVTT\n", Format::WebVtt)));
    }


    public function testOffsetMapsCueTimesToTheStreamStart(): void
    {
        $this->assertSame(0.0, (new TimestampMap(900000))->offset(900000));
        $this->assertSame(10.0, (new TimestampMap(900000))->offset(0));
        $this->assertSame(-3600.0, (new TimestampMap(324000000, 3600))->offset(324000000));
        $this->assertSame(0.0, (new TimestampMap(324000000, 3600))->offset(0));
    }


    public function testOffsetAllowsForWrapped33BitTimestamps(): void
    {
        $this->assertSame(2.0, (new TimestampMap(90000))->offset(TimestampMap::MPEGTS_WRAP - 90000));
        $this->assertSame(-2.0, (new TimestampMap(TimestampMap::MPEGTS_WRAP - 90000))->offset(90000));
    }
}
