<?php

namespace SubtitleToolbox;

use Doctrine\Common\Collections\ArrayCollection as Collection;
use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Parsers\SubtitleParser;

class SubtitleTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorMakesCollectionOutOfCues()
    {
        $subtitle = new Subtitle();

        $this->assertSame(Collection::class, get_class($subtitle->getCues()));
    }


    public function testAddRemoveAndGetCuesWithOddSpacing()
    {
        $subtitle = new Subtitle();

        $this->assertSame(0, $subtitle->getCues()->count());

        $subtitle->addCue(new SubtitleCue(1.2, 3.4, "line1\r\nline2"));
        $subtitle->addCue(new SubtitleCue(5.6, 7.89, "line3  \r\r\n\n\r\n\t\tline4"));

        $this->assertSame(2, $subtitle->getCues()->count());
        $this->assertSame(1.2, $subtitle->getCues()->first()->getStart());
        $this->assertSame(3.4, $subtitle->getCues()->first()->getEnd());
        $this->assertSame("line1\nline2", $subtitle->getCues()->first()->getText());
        $this->assertSame(5.6, $subtitle->getCues()->get(1)->getStart());
        $this->assertSame(7.89, $subtitle->getCues()->get(1)->getEnd());
        $this->assertSame("line3\nline4", $subtitle->getCues()->get(1)->getText());
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

        $this->assertSame($cue1, $subtitle->getCues()->get(0));
        $this->assertSame($cue3, $subtitle->getCues()->get(1));
        $this->assertSame($cue2, $subtitle->getCues()->get(2));
    }


    public function testGetErrors()
    {
        $subtitle = new Subtitle();
        $this->assertContains("subtitle contains no cues", $subtitle->getErrors()->first());

        $subtitle->addCue($cue1 = new SubtitleCue(1, 2, "text1"), false);
        $subtitle->addCue($cue2 = new SubtitleCue(5, 6, "text2"), false);
        $subtitle->addCue($cue3 = new SubtitleCue(3, 4, "text3"), false);

        $this->assertContains("before its predecessor's end-time", $subtitle->getErrors()->first());

        $subtitle->reIndexCues();
        $this->assertTrue($subtitle->getErrors()->isEmpty());

        $subtitle->removeCue(1, false);
        $this->assertContains("we expected it to be", $subtitle->getErrors()->first());

        $subtitle->addCue(new SubtitleCue(9, 1, "text4"));
        $this->assertContains("is after its own end-time", $subtitle->getErrors()->first());

        $subtitle->removeCue(2);
        $this->assertTrue($subtitle->getErrors()->isEmpty());
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
}
