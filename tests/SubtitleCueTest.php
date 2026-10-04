<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SubtitleCueTest extends TestCase
{
    public function testConstructorSetsAttributes()
    {
        $cue = new SubtitleCue(
            $start = 0.1,
            $end = 0.33,
            $text = (
                ($line1 = "this is a line") .
                "\n" .
                ($line2 = "with a linebreak")
            )
        );

        $this->assertSame(0.1, $cue->getStart());
        $this->assertSame(0.33, $cue->getEnd());
        $this->assertSame($text, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testGetAndSetStart()
    {
        $cue = new SubtitleCue();
        $cue->setStart($start = 123.456);

        $this->assertSame($start, $cue->getStart());
    }


    public function testGetAndSetEnd()
    {
        $cue = new SubtitleCue();
        $cue->setEnd($end = 123.456);

        $this->assertSame($end, $cue->getEnd());
    }


    public function testSetLinesWithWindowsStringAndTooManySpacesAndTabs()
    {
        $cue = new SubtitleCue();
        $cue->setLines(
            $text = (
                ($line1 = "this is a       line") .
                "\r\n\r\n" .
                ($line2 = "with a \t\t   linebreak")
            )
        );

        $this->assertNotEquals($text, $cue->getText());
        $this->assertSame(StringHelpers::cleanString($text), $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertNotEquals($line1, $cue->getLines()[0]);
        $this->assertNotEquals($line2, $cue->getLines()[1]);
        $this->assertSame(StringHelpers::cleanString($line1), $cue->getLines()[0]);
        $this->assertSame(StringHelpers::cleanString($line2), $cue->getLines()[1]);
    }


    public function testSetLinesWithArray()
    {
        $cue = new SubtitleCue();
        $cue->setLines($lines = [$line1 = "this is a line", $line2 = "with a linebreak"]);

        $this->assertSame($line1 . "\n" . $line2, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesByString()
    {
        $cue = new SubtitleCue();
        $cue->setLinesByString(
            $text = (
                ($line1 = "this is a line") .
                "\n" .
                ($line2 = "with a linebreak")
            )
        );

        $this->assertSame($text, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesByArray()
    {
        $cue = new SubtitleCue();
        $cue->setLinesByArray($lines = [$line1 = "this is a line", $line2 = "with a linebreak"]);

        $this->assertSame($line1 . "\n" . $line2, $cue->getText());
        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testAddLine()
    {
        $cue = new SubtitleCue();
        $this->assertSame(0, count($cue->getLines()));

        $cue->addLine($line1 = "first line");
        $cue->addLine($line2 = "second line");

        $this->assertSame(2, count($cue->getLines()));
        $this->assertSame($line1, $cue->getLines()[0]);
        $this->assertSame($line2, $cue->getLines()[1]);
    }


    public function testSetLinesRejectsOtherTypes(): void
    {
        $this->expectException(\TypeError::class);
        (new SubtitleCue())->setLines(123.456);
    }


    public function testGetTextOfCueWithoutLinesIsEmpty(): void
    {
        $this->assertSame("", (new SubtitleCue())->getText());
        $this->assertSame("", (new SubtitleCue(0, 1, ["", " "]))->getText());
    }


    public function testIdentifierIsNullByDefault(): void
    {
        $this->assertNull((new SubtitleCue())->getIdentifier());
    }


    public function testGetAndSetIdentifier(): void
    {
        $cue = (new SubtitleCue())->setIdentifier("intro");
        $this->assertSame("intro", $cue->getIdentifier());

        $cue->setIdentifier(null);
        $this->assertNull($cue->getIdentifier());
    }


    public function testAlignmentIsNullByDefault(): void
    {
        $this->assertNull((new SubtitleCue())->getAlignment());
    }


    public function testGetAndSetAlignment(): void
    {
        $cue = new SubtitleCue();

        foreach ([1, 2, 8, 9] as $alignment) {
            $this->assertSame($alignment, $cue->setAlignment($alignment)->getAlignment());
        }

        $this->assertNull($cue->setAlignment(null)->getAlignment());
    }


    public function testAlignmentOutOfRangeThrowsException(): void
    {
        foreach ([0, 10, -1] as $alignment) {
            try {
                (new SubtitleCue())->setAlignment($alignment);
                $this->fail("Alignment $alignment was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("must be a number from 1 to 9", $e->getMessage());
            }
        }
    }


    public function testFormatDataIsEmptyByDefault(): void
    {
        $this->assertSame([], (new SubtitleCue())->getFormatData("ass"));
    }


    public function testSetAndGetFormatDataPerFormat(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("vtt", ["region" => "top"]);

        $this->assertSame(["style" => "Default", "marginV" => 10], $object->getFormatData("ass"));
        $this->assertSame(["region" => "top"], $object->getFormatData("vtt"));
        $this->assertSame([], $object->getFormatData("srt"));
    }


    public function testSettingFormatDataReplacesPreviousData(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("ass", ["style" => "Sign"]);

        $this->assertSame(["style" => "Sign"], $object->getFormatData("ass"));

        $object->setFormatData("ass", []);
        $this->assertSame([], $object->getFormatData("ass"));
    }


    public function testGetAllFormatDataReturnsEveryFormat(): void
    {
        $object = (new SubtitleCue())
            ->setFormatData("ass", ["style" => "Default"])
            ->setFormatData("vtt", ["region" => "top"])
            ->setFormatData("vtt", []);

        $this->assertSame(["ass" => ["style" => "Default"]], $object->getAllFormatData());
        $this->assertSame([], (new SubtitleCue())->getAllFormatData());
    }
}
