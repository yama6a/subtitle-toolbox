<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubtitleParser;

class SubtitleTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorMakesArrayOutOfCues()
    {
        $subtitle = new Subtitle();

        $this->assertIsArray($subtitle->getCues());
    }


    public function testAddRemoveAndGetCuesWithOddSpacing()
    {
        $subtitle = new Subtitle();

        $this->assertSame(0, count($subtitle->getCues()));

        $subtitle->addCue(new SubtitleCue(1.2, 3.4, "line1\r\nline2"));
        $subtitle->addCue(new SubtitleCue(5.6, 7.89, "line3  \r\r\n\n\r\n\t\tline4"));

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame(1.2, $subtitle->getCues()[0]->getStart());
        $this->assertSame(3.4, $subtitle->getCues()[0]->getEnd());
        $this->assertSame("line1\nline2", $subtitle->getCues()[0]->getText());
        $this->assertSame(5.6, $subtitle->getCues()[1]->getStart());
        $this->assertSame(7.89, $subtitle->getCues()[1]->getEnd());
        $this->assertSame("line3\nline4", $subtitle->getCues()[1]->getText());
    }


    public function testRemovingInexistendCueThrowsException()
    {
        $subtitle = new Subtitle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("cue not found");
        $subtitle->removeCue(123);

    }


    public function testAddCueTriggersReIndex()
    {
        $subtitle = new Subtitle();
        $subtitle->addCue($cue1 = new SubtitleCue(1, 2, "text1"));
        $subtitle->addCue($cue2 = new SubtitleCue(5, 6, "text2"));
        $subtitle->addCue($cue3 = new SubtitleCue(3, 4, "text3"));

        $this->assertSame($cue1, $subtitle->getCues()[0]);
        $this->assertSame($cue3, $subtitle->getCues()[1]);
        $this->assertSame($cue2, $subtitle->getCues()[2]);
    }


    public function testGetErrors()
    {
        $subtitle = new Subtitle();
        $this->assertStringContainsString("subtitle contains no cues", $subtitle->getErrors()[0]);

        $subtitle->addCue($cue1 = new SubtitleCue(1, 2, "text1"), false);
        $subtitle->addCue($cue2 = new SubtitleCue(5, 6, "text2"), false);
        $subtitle->addCue($cue3 = new SubtitleCue(3, 4, "text3"), false);

        $this->assertStringContainsString("before its predecessor's end-time", $subtitle->getErrors()[0]);

        $subtitle->reIndexCues();
        $this->assertEmpty($subtitle->getErrors());

        $subtitle->removeCue(1, false);
        $this->assertStringContainsString("we expected it to be", $subtitle->getErrors()[0]);

        $subtitle->addCue(new SubtitleCue(9, 1, "text4"));
        $this->assertStringContainsString("is after its own end-time", $subtitle->getErrors()[0]);

        $subtitle->removeCue(2);
        $this->assertEmpty($subtitle->getErrors());
    }


    public function testParsingWithInvalidParserThrowsException()
    {
        $this->expectException(InvalidParserException::class);
        $this->expectExceptionMessage("parser stdClass is not of type " . SubtitleParser::class);
        Subtitle::parse("", \stdClass::class);
    }


    public function testFormattingWithInvalidFormatterThrowsException()
    {
        $this->expectException(InvalidFormatterException::class);
        $this->expectExceptionMessage("formatter stdClass is not of type " . SubtitleFormatter::class);
        (new Subtitle())->format(\stdClass::class);
    }


    public function testReIndexSortsStartsLessThanOneSecondApart(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.5, 2, "later"));
        $subtitle->addCue(new SubtitleCue(1.2, 1.4, "earlier"));

        $this->assertSame("earlier", $subtitle->getCues()[0]->getText());
        $this->assertSame("later", $subtitle->getCues()[1]->getText());
    }


    public function testParsingWithUnknownClassThrowsException(): void
    {
        $this->expectException(InvalidParserException::class);
        Subtitle::parse("", "SubtitleToolbox\\Parsers\\DoesNotExist");
    }


    public function testFormattingWithUnknownClassThrowsException(): void
    {
        $this->expectException(InvalidFormatterException::class);
        (new Subtitle())->format("SubtitleToolbox\\Formatters\\DoesNotExist");
    }


    public function testFormattersNumberCuesFromOneAfterRemovalWithoutReIndex(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->removeCue(0, false);

        $this->assertSame(
            "\u{feff}1\n00:00:03,000 --> 00:00:04,000\nsecond\n",
            $subtitle->format(SubRipFormatter::class)
        );
        $this->assertSame(
            "\u{feff}WEBVTT\n\n1\n00:00:03.000 --> 00:00:04.000\nsecond\n",
            $subtitle->format(WebVttFormatter::class)
        );
    }
}
