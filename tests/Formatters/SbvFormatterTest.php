<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SbvFormatterTest extends TestCase
{
    public function testValidFileRoundTrips(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"), SbvParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"),
            $subtitle->format(SbvFormatter::class)
        );
    }


    public function testHoursAreWrittenWithoutLeadingZero(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/sbv/two_digit_hours.sbv"), SbvParser::class);

        $this->assertSame(
            "0:00:01.500,0:00:04.000\nHello world\n\n1:00:05.000,1:00:07.250\nSecond cue\non two lines\n",
            $subtitle->format(SbvFormatter::class)
        );
    }


    public function testSubRipConvertsToSbvWithoutMarkup(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/sbv/from_srt.sbv"),
            $subtitle->format(SbvFormatter::class)
        );
    }


    public function testMarkupIsStrippedAndEntitiesAreDecoded(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i>Tom</i> &amp; <b>Jerry</b>", "&lt;3 caf&eacute; &quot;ok&quot; &#39;yes&#39;"]));

        $this->assertSame(
            "0:00:01.000,0:00:02.000\nTom & Jerry\n<3 café \"ok\" 'yes'\n",
            $subtitle->format(SbvFormatter::class)
        );
    }


    public function testLinesThatAreEmptyAfterStrippingAreDropped(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["<i></i>", "Text"]))
            ->addCue(new SubtitleCue(3, 4, ["<b> </b>"]))
            ->addCue(new SubtitleCue(5, 6, "Last"));

        $this->assertSame(
            "0:00:01.000,0:00:02.000\nText\n\n0:00:05.000,0:00:06.000\nLast\n",
            $subtitle->format(SbvFormatter::class)
        );
    }


    public function testOutputHasNoUtf8Bom(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Text"));

        $this->assertStringStartsWith("0:00:01.000", $subtitle->format(SbvFormatter::class));
    }
}
