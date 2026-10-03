<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Validation\ValidationResult;
use SubtitleToolbox\Validation\ValidationRules;

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


    public function testValidateWithTheStructureRulesFindsEachStructureProblem(): void
    {
        $rules    = ValidationRules::structure();
        $problems = fn (Subtitle $subtitle): array => array_map(
            fn (ValidationResult $result): array => [$result->getCueIndex(), $result->getRule(), $result->getValue()],
            $subtitle->validate($rules)
        );

        $subtitle = new Subtitle();
        $this->assertSame([[null, ValidationResult::RULE_REQUIRE_CUES, 0]], $problems($subtitle));

        $subtitle->addCue(new SubtitleCue(1, 2, "text1"), false);
        $subtitle->addCue(new SubtitleCue(5, 6, "text2"), false);
        $subtitle->addCue(new SubtitleCue(3, 4, "text3"), false);
        $this->assertSame([
            [2, ValidationResult::RULE_UNSORTED_CUES, 2.0],
            [2, ValidationResult::RULE_OVERLAP, 3.0],
        ], $problems($subtitle));

        $subtitle->reIndexCues();
        $this->assertSame([], $problems($subtitle));

        $subtitle->getCues()[1]->setEnd(5.5);
        $this->assertSame([[2, ValidationResult::RULE_OVERLAP, 0.5]], $problems($subtitle));
        $subtitle->getCues()[1]->setEnd(4);

        $subtitle->removeCue(1, false);
        $this->assertSame([[2, ValidationResult::RULE_INDEX_GAP, 1]], $problems($subtitle));

        $subtitle->addCue(new SubtitleCue(9, 1, "text4"));
        $this->assertSame([[2, ValidationResult::RULE_NEGATIVE_DURATION, -8.0]], $problems($subtitle));

        $subtitle->removeCue(2);
        $this->assertSame([], $problems($subtitle));
    }


    public function testFromStringThrowsForAFormatWithoutParser(): void
    {
        $this->expectException(InvalidParserException::class);
        $this->expectExceptionMessage("The format txt can be written but not read.");
        Subtitle::fromString("text", Format::PlainText);
    }


    public function testToStringThrowsForAFormatWithoutFormatter(): void
    {
        $this->expectException(InvalidFormatterException::class);
        $this->expectExceptionMessage("The format whisper can be read but not written.");
        (new Subtitle())->toString(Format::Whisper);
    }


    public function testFromStringAutoDetectFormatSkipsFormatsThatAreNotAutoDetected(): void
    {
        $this->expectException(InvalidParserException::class);
        Subtitle::fromStringAutoDetectFormat(file_get_contents(__DIR__ . "/files/chapters/ffmetadata/real/m4b_audiobook.ffmeta"));
    }


    public function testFromStringAutoDetectFormatConvertsTheEncoding(): void
    {
        $content = file_get_contents(__DIR__ . "/files/encoding/french-windows-1252.srt");

        $this->assertEquals(
            Subtitle::fromString($content, Format::SubRip, new ReadOptions(encoding: "Windows-1252")),
            Subtitle::fromStringAutoDetectFormat($content, new ReadOptions(encoding: "Windows-1252"))
        );
    }


    public function testReIndexSortsStartsLessThanOneSecondApart(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.5, 2, "later"));
        $subtitle->addCue(new SubtitleCue(1.2, 1.4, "earlier"));

        $this->assertSame("earlier", $subtitle->getCues()[0]->getText());
        $this->assertSame("later", $subtitle->getCues()[1]->getText());
    }


    public function testFormattersNumberCuesFromOneAfterRemovalWithoutReIndex(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->removeCue(0, false);

        $this->assertSame(
            "\u{feff}1\n00:00:03,000 --> 00:00:04,000\nsecond\n",
            $subtitle->toString(Format::SubRip)
        );
        $this->assertSame(
            "\u{feff}WEBVTT\n\n1\n00:00:03.000 --> 00:00:04.000\nsecond\n",
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testMetadataIsEmptyByDefault(): void
    {
        $subtitle = new Subtitle();

        $this->assertSame([], $subtitle->getAllMetadata());
        $this->assertNull($subtitle->getMetadata(Subtitle::METADATA_TITLE));
    }


    public function testSetAndGetMetadata(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Yesterday")
            ->setMetadata(Subtitle::METADATA_ARTIST, "The Beatles")
            ->setMetadata("custom", "");

        $this->assertSame("Yesterday", $subtitle->getMetadata("title"));
        $this->assertSame("", $subtitle->getMetadata("custom"));
        $this->assertSame(
            ["title" => "Yesterday", "artist" => "The Beatles", "custom" => ""],
            $subtitle->getAllMetadata()
        );
    }


    public function testSettingMetadataOverwritesValue(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "de");

        $this->assertSame(["language" => "de"], $subtitle->getAllMetadata());
    }


    public function testSettingMetadataToNullRemovesKey(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->setMetadata(Subtitle::METADATA_ALBUM, "Help!")
            ->setMetadata(Subtitle::METADATA_AUTHOR, null)
            ->setMetadata("missing", null);

        $this->assertNull($subtitle->getMetadata("author"));
        $this->assertSame(["album" => "Help!"], $subtitle->getAllMetadata());
    }


    public function testCommentsAreEmptyByDefault(): void
    {
        $this->assertSame([], (new Subtitle())->getComments());
    }


    public function testAddCommentKeepsCommentsInCueOrder(): void
    {
        $subtitle = (new Subtitle())
            ->addComment("after last cue", 2)
            ->addComment("first before cue 0", 0)
            ->addComment("second before cue 0", 0)
            ->addComment("before cue 1", 1);

        $this->assertSame(
            [
                ["text" => "first before cue 0", "beforeCueIndex" => 0],
                ["text" => "second before cue 0", "beforeCueIndex" => 0],
                ["text" => "before cue 1", "beforeCueIndex" => 1],
                ["text" => "after last cue", "beforeCueIndex" => 2],
            ],
            $subtitle->getComments()
        );
    }


    public function testAddCommentWithNegativeIndexThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must not be negative");
        (new Subtitle())->addComment("text", -1);
    }


    public function testReIndexKeepsEachCommentBeforeItsCue(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(5, 6, "late"), false);
        $subtitle->addCue(new SubtitleCue(1, 2, "early"), false);
        $subtitle->addComment("before late", 0);
        $subtitle->addComment("before early", 1);
        $subtitle->addComment("at the end", 2);

        $subtitle->reIndexCues();

        $this->assertSame("early", $subtitle->getCues()[0]->getText());
        $this->assertSame(
            [
                ["text" => "before early", "beforeCueIndex" => 0],
                ["text" => "before late", "beforeCueIndex" => 1],
                ["text" => "at the end", "beforeCueIndex" => 2],
            ],
            $subtitle->getComments()
        );
    }


    public function testCommentBeforeAddedCueMovesWithIt(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addComment("before first", 1);
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));

        $this->assertSame([["text" => "before first", "beforeCueIndex" => 0]], $subtitle->getComments());
    }


    public function testCommentBeforeRemovedCueMovesToNextCue(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addCue(new SubtitleCue(5, 6, "third"));
        $subtitle->addComment("before second", 1);
        $subtitle->addComment("before third", 2);

        $subtitle->removeCue(1);

        $this->assertSame(
            [
                ["text" => "before second", "beforeCueIndex" => 1],
                ["text" => "before third", "beforeCueIndex" => 1],
            ],
            $subtitle->getComments()
        );
    }


    public function testCommentBeforeRemovedLastCueMovesToEnd(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addComment("before second", 1);

        $subtitle->removeCue(1);

        $this->assertSame([["text" => "before second", "beforeCueIndex" => 1]], $subtitle->getComments());
    }


    public function testCommentKeepsIndexAfterRemovalWithoutReIndex(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "first"));
        $subtitle->addCue(new SubtitleCue(3, 4, "second"));
        $subtitle->addComment("before second", 1);

        $subtitle->removeCue(0, false);

        $this->assertSame([["text" => "before second", "beforeCueIndex" => 1]], $subtitle->getComments());

        $subtitle->reIndexCues();

        $this->assertSame([["text" => "before second", "beforeCueIndex" => 0]], $subtitle->getComments());
    }


    public function testFormatDataIsEmptyByDefault(): void
    {
        $this->assertSame([], (new Subtitle())->getFormatData("ass"));
    }


    public function testSetAndGetFormatDataPerFormat(): void
    {
        $object = (new Subtitle())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("vtt", ["region" => "top"]);

        $this->assertSame(["style" => "Default", "marginV" => 10], $object->getFormatData("ass"));
        $this->assertSame(["region" => "top"], $object->getFormatData("vtt"));
        $this->assertSame([], $object->getFormatData("srt"));
    }


    public function testSettingFormatDataReplacesPreviousData(): void
    {
        $object = (new Subtitle())
            ->setFormatData("ass", ["style" => "Default", "marginV" => 10])
            ->setFormatData("ass", ["style" => "Sign"]);

        $this->assertSame(["style" => "Sign"], $object->getFormatData("ass"));

        $object->setFormatData("ass", []);
        $this->assertSame([], $object->getFormatData("ass"));
    }
}
