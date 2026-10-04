<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CueEditingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/editing/";


    private function makeSubtitle(array $cues): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($cues as [$start, $end, $text]) {
            $subtitle->addCue(new SubtitleCue($start, $end, $text));
        }

        return $subtitle;
    }


    private function describeCues(Subtitle $subtitle): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText()],
            $subtitle->getCues()
        );
    }


    private function parseHarbourTour(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::DIR . "harbour_tour.vtt"), Format::WebVtt);
    }


    private function getCommentsByCueText(Subtitle $subtitle): array
    {
        $cues = $subtitle->getCues();

        return array_map(
            fn (array $comment): array => [$comment["text"], ($cues[$comment["beforeCueIndex"]] ?? null)?->getText()],
            $subtitle->getComments()
        );
    }


    public function testRealFilesParse(): void
    {
        $part1   = Subtitle::fromString(file_get_contents(self::DIR . "film_part1.srt"), Format::SubRip);
        $part2   = Subtitle::fromString(file_get_contents(self::DIR . "film_part2.srt"), Format::SubRip);
        $harbour = $this->parseHarbourTour();

        $this->assertSame(
            [[4.2, 7.45, "The ferry leaves at seven.\nDo not be late."], [3115.5, 3119.8, "We stop here for tonight."]],
            [$this->describeCues($part1)[0], $this->describeCues($part1)[3]]
        );
        $this->assertCount(4, $part1->getCues());
        $this->assertSame(
            [[1.25, 4.0, "PART TWO"], [760.125, 763.0, "Then we row."]],
            [$this->describeCues($part2)[0], $this->describeCues($part2)[2]]
        );
        $this->assertCount(3, $part2->getCues());
        $this->assertSame(
            [[1.0, 4.0, "Welcome to the harbour tour."], [18.0, 20.0, "Next stop, the fish market."]],
            [$this->describeCues($harbour)[0], $this->describeCues($harbour)[5]]
        );
        $this->assertCount(6, $harbour->getCues());
        $this->assertCount(5, $harbour->getComments());
    }


    public function testRealFileRoundTrip(): void
    {
        $harbour  = $this->parseHarbourTour();
        $reparsed = Subtitle::fromString($harbour->toString(Format::WebVtt), Format::WebVtt);

        $this->assertSame($this->describeCues($harbour), $this->describeCues($reparsed));
        $this->assertSame($harbour->getComments(), $reparsed->getComments());
        $this->assertSame($harbour->getFormatData("vtt"), $reparsed->getFormatData("vtt"));
    }


    public function testMergeFilmPartsWithOffset(): void
    {
        $part1 = Subtitle::fromString(file_get_contents(self::DIR . "film_part1.srt"), Format::SubRip);
        $part2 = Subtitle::fromString(file_get_contents(self::DIR . "film_part2.srt"), Format::SubRip);

        $this->assertSame($part1, $part1->merge($part2, 3130));
        $this->assertSame(file_get_contents(self::DIR . "film_merged.srt"), $part1->toString(Format::SubRip));
        $this->assertSame(1.25, $part2->getCues()[0]->getStart());
    }


    public function testMergeKeepsMetadataCommentsAndFormatDataOfThisOnConflict(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2, "one"], [3, 4, "two"]])
            ->setMetadata(Subtitle::METADATA_TITLE, "Part one")
            ->setFormatData("vtt", ["header" => "first"])
            ->addComment("before two", 1)
            ->addComment("end of part one", 2);
        $other = $this->makeSubtitle([[1, 2, "three"]])
            ->setMetadata(Subtitle::METADATA_TITLE, "Part two")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setFormatData("vtt", ["header" => "second"])
            ->setFormatData("ass", ["scriptInfo" => []])
            ->addComment("start of part two", 0)
            ->addComment("end of part two", 1);

        $subtitle->merge($other, 10);

        $this->assertSame([[1.0, 2.0, "one"], [3.0, 4.0, "two"], [11.0, 12.0, "three"]], $this->describeCues($subtitle));
        $this->assertSame(["title" => "Part one", "language" => "en"], $subtitle->getAllMetadata());
        $this->assertSame(["header" => "first"], $subtitle->getFormatData("vtt"));
        $this->assertSame(["scriptInfo" => []], $subtitle->getFormatData("ass"));
        $this->assertSame([
            ["text" => "before two", "beforeCueIndex" => 1],
            ["text" => "end of part one", "beforeCueIndex" => 2],
            ["text" => "start of part two", "beforeCueIndex" => 2],
            ["text" => "end of part two", "beforeCueIndex" => 3],
        ], $subtitle->getComments());
    }


    public function testMergeSortsOverlappingCuesAndKeepsCommentsWithTheirCues(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2, "one"], [5, 6, "three"]])->addComment("about three", 1);
        $other    = $this->makeSubtitle([[3, 4, "two"]])->addComment("about two", 0);

        $subtitle->merge($other);

        $this->assertSame(["one", "two", "three"], array_column($this->describeCues($subtitle), 2));
        $this->assertSame([["about two", "two"], ["about three", "three"]], $this->getCommentsByCueText($subtitle));
    }


    public function testMergeClampsNegativeTimesToZero(): void
    {
        $subtitle = (new Subtitle())->merge($this->makeSubtitle([[1, 3, "one"]]), -2);

        $this->assertSame([[0.0, 1.0, "one"]], $this->describeCues($subtitle));
    }


    public function testSliceRealFile(): void
    {
        $subtitle = $this->parseHarbourTour();
        $original = $subtitle->toString(Format::WebVtt);

        $slice = $subtitle->withSlice(6, 16, true);

        $this->assertNotSame($subtitle, $slice);
        $this->assertSame(file_get_contents(self::DIR . "harbour_tour_slice.vtt"), $slice->toString(Format::WebVtt));
        $this->assertSame($original, $subtitle->toString(Format::WebVtt));
    }


    public function testSliceCutsCuesAtBoundariesAndCopiesMetadataAndFormatData(): void
    {
        $subtitle = $this->makeSubtitle([[1, 3, "one"], [4, 6, "two"], [7, 9, "three"]])
            ->setMetadata(Subtitle::METADATA_TITLE, "Clip")
            ->setFormatData("vtt", ["header" => "clip"]);
        $subtitle->getCues()[1]->setFormatData("vtt", ["line" => "0"])->setIdentifier("middle");

        $slice = $subtitle->withSlice(2, 8);

        $this->assertSame([[2.0, 3.0, "one"], [4.0, 6.0, "two"], [7.0, 8.0, "three"]], $this->describeCues($slice));
        $this->assertSame(["title" => "Clip"], $slice->getAllMetadata());
        $this->assertSame(["header" => "clip"], $slice->getFormatData("vtt"));
        $this->assertSame(["line" => "0"], $slice->getCues()[1]->getFormatData("vtt"));
        $this->assertSame("middle", $slice->getCues()[1]->getIdentifier());
        $this->assertNotSame($subtitle->getCues()[1], $slice->getCues()[1]);
        $this->assertSame([1.0, 3.0, "one"], $this->describeCues($subtitle)[0]);
    }


    public function testSliceDropsCuesThatOnlyTouchTheRange(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2, "before"], [2, 3, "inside"], [3, 4, "after"]]);

        $this->assertSame([[2.0, 3.0, "inside"]], $this->describeCues($subtitle->withSlice(2, 3)));
    }


    public function testSliceKeepsCommentAfterLastCueOnlyWhenTheLastCueIsKept(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2, "one"], [3, 4, "two"]])
            ->addComment("first", 0)
            ->addComment("last", 2);

        $this->assertSame(
            [["text" => "last", "beforeCueIndex" => 1]],
            $subtitle->withSlice(2.5, 10, true)->getComments()
        );
        $this->assertSame(
            [["text" => "first", "beforeCueIndex" => 0]],
            $subtitle->withSlice(0, 2.5)->getComments()
        );
    }


    public function testSliceWithStartAfterEndThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The slice start 5 must not be after the slice end 4.");
        $this->makeSubtitle([[1, 2, "one"]])->withSlice(5, 4);
    }


    public function testSplitCueRealFile(): void
    {
        $subtitle = $this->parseHarbourTour();

        $this->assertSame($subtitle, $subtitle->splitCue(2, 9.5, 1));
        $this->assertSame(file_get_contents(self::DIR . "harbour_tour_split.vtt"), $subtitle->toString(Format::WebVtt));
    }


    public function testSplitCueKeepsCommentsAndCueDataAndClearsSecondIdentifier(): void
    {
        $subtitle = $this->makeSubtitle([[0, 6, "First sentence.\nSecond sentence."], [7, 8, "next"]])
            ->addComment("about the split cue", 0)
            ->addComment("about next", 1);
        $subtitle->getCues()[0]->setIdentifier("intro")->setAlignment(8);

        $subtitle->splitCue(0, 3, 1);

        $cues = $subtitle->getCues();
        $this->assertSame(
            [[0.0, 3.0, "First sentence."], [3.0, 6.0, "Second sentence."], [7.0, 8.0, "next"]],
            $this->describeCues($subtitle)
        );
        $this->assertSame(["intro", null], [$cues[0]->getIdentifier(), $cues[1]->getIdentifier()]);
        $this->assertSame([8, 8], [$cues[0]->getAlignment(), $cues[1]->getAlignment()]);
        $this->assertSame(
            [["about the split cue", "First sentence."], ["about next", "next"]],
            $this->getCommentsByCueText($subtitle)
        );
    }


    public function testSplitCueOutsideTheCueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot split cue 0 at 6 - the time must be after the cue start 0 " .
                                      "and before the cue end 6.");
        $this->makeSubtitle([[0, 6, "a\nb"]])->splitCue(0, 6, 1);
    }


    public function testSplitCueAfterLastLineThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot split cue 0 after line 2 - the cue has 2 lines.");
        $this->makeSubtitle([[0, 6, "a\nb"]])->splitCue(0, 3, 2);
    }


    public function testSplitUnknownCueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot edit cue 3 - cue not found!");
        $this->makeSubtitle([[0, 6, "a\nb"]])->splitCue(3, 3, 1);
    }


    public function testJoinCuesRealFile(): void
    {
        $subtitle = $this->parseHarbourTour();

        $this->assertSame($subtitle, $subtitle->joinCues(3, 4));
        $this->assertSame(file_get_contents(self::DIR . "harbour_tour_joined.vtt"), $subtitle->toString(Format::WebVtt));
    }


    public function testJoinCuesKeepsCommentsAndFirstCueData(): void
    {
        $subtitle = $this->makeSubtitle([[0, 1, "zero"], [1, 2, "one"], [2, 4, "two"], [5, 6, "three"], [7, 8, "four"]])
            ->addComment("about one", 1)
            ->addComment("about two", 2)
            ->addComment("about three", 3)
            ->addComment("about four", 4)
            ->addComment("end", 5);
        $subtitle->getCues()[1]->setIdentifier("first")->setAlignment(7);

        $subtitle->joinCues(1, 3);

        $cues = $subtitle->getCues();
        $this->assertSame([[0.0, 1.0, "zero"], [1.0, 6.0, "one\ntwo\nthree"], [7.0, 8.0, "four"]], $this->describeCues($subtitle));
        $this->assertSame(["first", 7], [$cues[1]->getIdentifier(), $cues[1]->getAlignment()]);
        $this->assertSame([
            ["about one", "one\ntwo\nthree"],
            ["about two", "one\ntwo\nthree"],
            ["about three", "one\ntwo\nthree"],
            ["about four", "four"],
            ["end", null],
        ], $this->getCommentsByCueText($subtitle));
    }


    public function testJoinCuesWithWrongOrderThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot join cues 1 to 1 - the first index must be lower than the last index.");
        $this->makeSubtitle([[0, 1, "zero"], [1, 2, "one"]])->joinCues(1, 1);
    }


    public function testJoinUnknownCueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot edit cue 2 - cue not found!");
        $this->makeSubtitle([[0, 1, "zero"], [1, 2, "one"]])->joinCues(0, 2);
    }


    public function testRemoveDuplicateCuesRealFile(): void
    {
        $subtitle = $this->parseHarbourTour();

        $this->assertSame($subtitle, $subtitle->removeDuplicateCues());
        $this->assertSame(
            file_get_contents(self::DIR . "harbour_tour_deduplicated.vtt"),
            $subtitle->toString(Format::WebVtt)
        );
    }


    public function testRemoveDuplicateCuesJoinsOnlyTouchingCuesWithTheSameText(): void
    {
        $subtitle = $this->makeSubtitle([
            [0, 1, "same"], [1, 2, "same"], [2, 3, "same"], [3.5, 4, "same"], [4, 5, "other"], [5, 6, "Other"],
        ])
            ->addComment("second", 1)
            ->addComment("third", 2)
            ->addComment("fourth", 3)
            ->addComment("end", 6);

        $subtitle->removeDuplicateCues();

        $this->assertSame(
            [[0.0, 3.0, "same"], [3.5, 4.0, "same"], [4.0, 5.0, "other"], [5.0, 6.0, "Other"]],
            $this->describeCues($subtitle)
        );
        $this->assertSame(
            [["second", "same"], ["third", "same"], ["fourth", "same"], ["end", null]],
            $this->getCommentsByCueText($subtitle)
        );
        $this->assertSame([0, 0, 1, 4], array_column($subtitle->getComments(), "beforeCueIndex"));
    }
}
