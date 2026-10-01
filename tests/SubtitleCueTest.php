<?php

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


    public function testSetLinesThrowsExceptionForUnexpectedBasicType()
    {
        $cue = new SubtitleCue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Can only set cue-text by string or array!");
        $this->expectExceptionMessage("by double");
        $cue->setLines(123.456);
    }


    public function testSetLinesThrowsExceptionForUnexpectedObjectType()
    {
        $cue = new SubtitleCue();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Can only set cue-text by string or array!");
        $this->expectExceptionMessage("by stdClass");
        $cue->setLines(new \stdClass());
    }


    public function testSetLinesExceptionNamesTheGettypeOfScalars(): void
    {
        $cue = new SubtitleCue(1.5, 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tried to set cue-text of cue [1.5 >>> 2] by NULL");
        $cue->setLines(null);
    }


    public function testSetLinesExceptionNamesIntegerType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("by integer");
        (new SubtitleCue())->setLines(123);
    }


    public function testSetLinesExceptionNamesAnonymousClass(): void
    {
        $object = new class {
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("by " . get_class($object));
        (new SubtitleCue())->setLines($object);
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
}
