<?php

namespace SubtitleToolbox\Diff;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubtitleDiffTest extends TestCase
{
    private function load(string $name): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/../files/diff/$name"), SubRipParser::class);
    }


    /** @return list<array{string, ?int, ?int}> */
    private function summarize(array $differences): array
    {
        return array_map(fn (CueDifference $difference): array =>
            [$difference->getKind(), $difference->getOldIndex(), $difference->getNewIndex()], $differences);
    }


    private function makeSubtitle(array $cues): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($cues as [$start, $end, $text]) {
            $subtitle->addCue(new SubtitleCue($start, $end, $text), false);
        }

        return $subtitle;
    }


    public function testRealFilesParse(): void
    {
        $original = $this->load("own_original.srt")->getCues();
        $edited   = $this->load("own_edited.srt")->getCues();

        $this->assertCount(13, $original);
        $this->assertSame([1.0, 3.5, "Good morning, and welcome\nto the coastal train."],
                          [$original[0]->getStart(), $original[0]->getEnd(), $original[0]->getText()]);
        $this->assertSame([40.0, 42.0, "Have a pleasant trip."],
                          [$original[12]->getStart(), $original[12]->getEnd(), $original[12]->getText()]);

        $this->assertCount(14, $edited);
        $this->assertSame([1.0, 3.5, "Good morning, and welcome\nto the coastal train."],
                          [$edited[0]->getStart(), $edited[0]->getEnd(), $edited[0]->getText()]);
        $this->assertSame([40.0, 42.0, "Have a pleasant trip."],
                          [$edited[13]->getStart(), $edited[13]->getEnd(), $edited[13]->getText()]);
    }


    public function testCompareRealFiles(): void
    {
        $original    = $this->load("own_original.srt");
        $edited      = $this->load("own_edited.srt");
        $differences = SubtitleDiff::compare($original, $edited);

        $this->assertSame([
            [CueDifference::KIND_TIMING_CHANGED, 1, 1],
            [CueDifference::KIND_TEXT_CHANGED, 3, 3],
            [CueDifference::KIND_TEXT_AND_TIMING_CHANGED, 5, 5],
            [CueDifference::KIND_ADDED, null, 6],
            [CueDifference::KIND_REMOVED, 7, null],
            [CueDifference::KIND_TEXT_AND_TIMING_CHANGED, 8, 8],
            [CueDifference::KIND_TEXT_CHANGED, 9, 9],
            [CueDifference::KIND_TEXT_CHANGED, 10, 10],
            [CueDifference::KIND_ADDED, null, 12],
        ], $this->summarize($differences));

        $this->assertSame($original->getCues()[3], $differences[1]->getOldCue());
        $this->assertSame($edited->getCues()[3], $differences[1]->getNewCue());
        $this->assertNull($differences[3]->getOldCue());
        $this->assertSame("Cards and coins are fine.", $differences[3]->getNewCue()->getText());
        $this->assertSame("Thank you.", $differences[4]->getOldCue()->getText());
        $this->assertNull($differences[4]->getNewCue());
    }


    public function testTextReportMatchesExpectedFile(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"));

        $this->assertStringEqualsFile(__DIR__ . "/../files/diff/own_report.txt", SubtitleDiff::toText($differences));
        $this->assertSame("", SubtitleDiff::toText([]));
    }


    public function testIgnoreFormatting(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"),
                                             new SubtitleDiffOptions(ignoreFormatting: true));

        $this->assertNotContains([CueDifference::KIND_TEXT_CHANGED, 9, 9], $this->summarize($differences));
        $this->assertCount(8, $differences);
    }


    public function testIgnoreWhitespace(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"),
                                             new SubtitleDiffOptions(ignoreWhitespace: true));

        $this->assertNotContains([CueDifference::KIND_TEXT_CHANGED, 10, 10], $this->summarize($differences));
        $this->assertCount(8, $differences);
    }


    public function testTextOnly(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"),
                                             new SubtitleDiffOptions(textOnly: true));

        $this->assertSame([
            [CueDifference::KIND_TEXT_CHANGED, 3, 3],
            [CueDifference::KIND_TEXT_CHANGED, 5, 5],
            [CueDifference::KIND_ADDED, null, 6],
            [CueDifference::KIND_REMOVED, 7, null],
            [CueDifference::KIND_TEXT_CHANGED, 8, 8],
            [CueDifference::KIND_TEXT_CHANGED, 9, 9],
            [CueDifference::KIND_TEXT_CHANGED, 10, 10],
            [CueDifference::KIND_ADDED, null, 12],
        ], $this->summarize($differences));
    }


    public function testTimeTolerance(): void
    {
        $original = $this->load("own_original.srt");
        $edited   = $this->load("own_edited.srt");

        $wide = $this->summarize(SubtitleDiff::compare($original, $edited, new SubtitleDiffOptions(timeTolerance: 0.25)));
        $this->assertNotContains([CueDifference::KIND_TIMING_CHANGED, 1, 1], $wide);
        $this->assertCount(8, $wide);

        $exact = $this->summarize(SubtitleDiff::compare($original, $edited, new SubtitleDiffOptions(timeTolerance: 0)));
        $this->assertContains([CueDifference::KIND_TEXT_AND_TIMING_CHANGED, 9, 9], $exact);
        $this->assertCount(9, $exact);
    }


    public function testNegativeTimeToleranceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubtitleDiffOptions(timeTolerance: -0.1);
    }


    public function testIsEqual(): void
    {
        $original = $this->load("own_original.srt");

        $this->assertTrue(SubtitleDiff::isEqual($original, $this->load("own_original.srt")));
        $this->assertFalse(SubtitleDiff::isEqual($original, $this->load("own_edited.srt")));
        $this->assertTrue(SubtitleDiff::isEqual(new Subtitle(), new Subtitle()));
        $this->assertFalse(SubtitleDiff::isEqual(new Subtitle(), $original));
    }


    public function testAddedCueDoesNotShiftLaterPairs(): void
    {
        $old = $this->makeSubtitle([[1, 2, "The train is late."], [3, 4, "The bakery is open."]]);
        $new = $this->makeSubtitle([[0, 0.5, "Welcome."], [1, 2, "The train is late."], [3, 4, "The bakery is open."]]);

        $this->assertSame([[CueDifference::KIND_ADDED, null, 0]], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testSameTextPairsWithoutTimeOverlap(): void
    {
        $old = $this->makeSubtitle([[10, 12, "The bakery opens at six."]]);
        $new = $this->makeSubtitle([[100, 102, "The bakery opens at six."]]);

        $this->assertSame([[CueDifference::KIND_TIMING_CHANGED, 0, 0]], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testUnrelatedCuesListRemovedBeforeAdded(): void
    {
        $old = $this->makeSubtitle([[1, 2, "Rain at noon."], [5, 6, "The harbour is closed."], [9, 10, "Goodbye."]]);
        $new = $this->makeSubtitle([[1, 2, "Rain at noon."], [7, 8, "Coffee is free today."], [9, 10, "Goodbye."]]);

        $this->assertSame([
            [CueDifference::KIND_REMOVED, 1, null],
            [CueDifference::KIND_ADDED, null, 1],
        ], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testShortOverlapWithNeighbourDoesNotPair(): void
    {
        $old = $this->makeSubtitle([[1, 3, "The train leaves now."]]);
        $new = $this->makeSubtitle([[2.9, 5, "A different line."]]);

        $this->assertSame([
            [CueDifference::KIND_REMOVED, 0, null],
            [CueDifference::KIND_ADDED, null, 0],
        ], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testIndexesAreCueKeys(): void
    {
        $old = $this->makeSubtitle([[1, 2, "One."], [3, 4, "Two."], [5, 6, "Three."]]);
        $new = $this->makeSubtitle([[1, 2, "One."], [3, 4, "Two."], [5, 6, "Three!"]]);
        $old->removeCue(0, false);
        $new->removeCue(0, false);

        $this->assertSame([[CueDifference::KIND_TEXT_CHANGED, 2, 2]], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testLongStretchWithoutSameTextPairsByTime(): void
    {
        $oldCues = [];
        $newCues = [];
        for ($index = 0; $index < 300; $index++) {
            $oldCues[] = [$index * 3, $index * 3 + 2, "line number $index"];
            if ($index !== 150) {
                $newCues[] = [$index * 3, $index * 3 + 2, "LINE NUMBER $index"];
            }
        }

        $summary = $this->summarize(SubtitleDiff::compare($this->makeSubtitle($oldCues), $this->makeSubtitle($newCues)));

        $this->assertCount(300, $summary);
        $this->assertSame([CueDifference::KIND_TEXT_CHANGED, 149, 149], $summary[149]);
        $this->assertSame([CueDifference::KIND_REMOVED, 150, null], $summary[150]);
        $this->assertSame([CueDifference::KIND_TEXT_CHANGED, 151, 150], $summary[151]);
    }
}
