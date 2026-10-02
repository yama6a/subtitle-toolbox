<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

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


    public function testStrikethroughTagIsKept(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<s>struck</s> <c.red>plain</c>"));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\n<s>struck</s> plain\n",
            $subtitle->format(SubRipFormatter::class)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nstruck plain\n",
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS])
        );
    }


    public function testTimestampsAreWrittenInTheStandardForm(): void
    {
        $subtitle = Subtitle::parse("1\n0:00:01.5 --> 0:00:02.25\nText\n", SubRipParser::class);

        $this->assertSame(
            "\u{feff}1\n00:00:01,500 --> 00:00:02,250\nText\n",
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testCoordinatesAreWrittenBack(): void
    {
        $raw      = "\u{feff}1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600 Y1:40 Y2:80\nText\n";
        $subtitle = Subtitle::parse($raw, SubRipParser::class);

        $this->assertSame($raw, $subtitle->format(SubRipFormatter::class));
    }


    public function testAlignmentIsWrittenAtTheStartOfTheFirstLine(): void
    {
        $subtitle = Subtitle::parse("1\n00:00:01,000 --> 00:00:04,000\n<i>The train</i> {\\an8}leaves\nsoon\n", SubRipParser::class);

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:04,000\n{\\an8}<i>The train</i> leaves\nsoon\n",
            $subtitle->format(SubRipFormatter::class)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:04,000\n{\\an8}The train leaves\nsoon\n",
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS])
        );
    }


    public function testDefaultAlignmentIsNotWritten(): void
    {
        $subtitle = (new Subtitle())
            ->addCue((new SubtitleCue(1, 2, "Bottom center"))->setAlignment(2))
            ->addCue(new SubtitleCue(3, 4, "Default"));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nBottom center\n\n2\n00:00:03,000 --> 00:00:04,000\nDefault\n",
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testLineThatBecomesEmptyIsDropped(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["Before", "<c.red></c>", "After"]));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nBefore\nAfter\n",
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testEntitiesAreDecoded(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["I &lt;3 bread &amp; jam", "<i>Salt &amp; pepper</i> 2 &gt; 1"]));

        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\n<i>Salt & pepper</i> 2 > 1\n",
            $subtitle->format(SubRipFormatter::class)
        );
        $this->assertSame(
            "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\nSalt & pepper 2 > 1\n",
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS])
        );
    }


    public function testTextWithLessThanAndAmpersandSurvivesARoundTrip(): void
    {
        $raw = "\u{feff}1\n00:00:01,000 --> 00:00:02,000\nI <3 bread & jam\n";

        $this->assertSame($raw, Subtitle::parse($raw, SubRipParser::class)->format(SubRipFormatter::class));
    }
}
