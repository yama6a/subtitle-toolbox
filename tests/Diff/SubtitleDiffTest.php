<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubtitleDiffTest extends TestCase
{
    private function load(string $name): Subtitle
    {
        return Subtitle::fromString(file_get_contents(__DIR__ . "/../files/diff/$name"), Format::SubRip);
    }


    /** @return list<array{string, ?int, ?int}> */
    private function summarize(array $differences): array
    {
        return array_map(fn (CueDifference $difference): array =>
            [$difference->kind, $difference->oldIndex, $difference->newIndex], $differences);
    }


    private function makeSubtitle(array $cues): Subtitle
    {
        $subtitle = new Subtitle();
        $added    = [];
        foreach ($cues as [$start, $end, $text]) {
            $added[] = new SubtitleCue($start, $end, $text);
        }
        $subtitle->addCues($added);

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
            [CueDifferenceKind::TimingChanged, 1, 1],
            [CueDifferenceKind::TextChanged, 3, 3],
            [CueDifferenceKind::TextAndTimingChanged, 5, 5],
            [CueDifferenceKind::Added, null, 6],
            [CueDifferenceKind::Removed, 7, null],
            [CueDifferenceKind::TextAndTimingChanged, 8, 8],
            [CueDifferenceKind::TextChanged, 9, 9],
            [CueDifferenceKind::TextChanged, 10, 10],
            [CueDifferenceKind::Added, null, 12],
        ], $this->summarize($differences));

        $this->assertSame($original->getCues()[3], $differences[1]->oldCue);
        $this->assertSame($edited->getCues()[3], $differences[1]->newCue);
        $this->assertNull($differences[3]->oldCue);
        $this->assertSame("Cards and coins are fine.", $differences[3]->newCue->getText());
        $this->assertSame("Thank you.", $differences[4]->oldCue->getText());
        $this->assertNull($differences[4]->newCue);
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

        $this->assertNotContains([CueDifferenceKind::TextChanged, 9, 9], $this->summarize($differences));
        $this->assertCount(8, $differences);
    }


    public function testIgnoreWhitespace(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"),
                                             new SubtitleDiffOptions(ignoreWhitespace: true));

        $this->assertNotContains([CueDifferenceKind::TextChanged, 10, 10], $this->summarize($differences));
        $this->assertCount(8, $differences);
    }


    public function testTextOnly(): void
    {
        $differences = SubtitleDiff::compare($this->load("own_original.srt"), $this->load("own_edited.srt"),
                                             new SubtitleDiffOptions(textOnly: true));

        $this->assertSame([
            [CueDifferenceKind::TextChanged, 3, 3],
            [CueDifferenceKind::TextChanged, 5, 5],
            [CueDifferenceKind::Added, null, 6],
            [CueDifferenceKind::Removed, 7, null],
            [CueDifferenceKind::TextChanged, 8, 8],
            [CueDifferenceKind::TextChanged, 9, 9],
            [CueDifferenceKind::TextChanged, 10, 10],
            [CueDifferenceKind::Added, null, 12],
        ], $this->summarize($differences));
    }


    public function testTimeTolerance(): void
    {
        $original = $this->load("own_original.srt");
        $edited   = $this->load("own_edited.srt");

        $wide = $this->summarize(SubtitleDiff::compare($original, $edited, new SubtitleDiffOptions(timeTolerance: 0.25)));
        $this->assertNotContains([CueDifferenceKind::TimingChanged, 1, 1], $wide);
        $this->assertCount(8, $wide);

        $exact = $this->summarize(SubtitleDiff::compare($original, $edited, new SubtitleDiffOptions(timeTolerance: 0)));
        $this->assertContains([CueDifferenceKind::TextAndTimingChanged, 9, 9], $exact);
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

        $this->assertSame([[CueDifferenceKind::Added, null, 0]], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testSameTextPairsWithoutTimeOverlap(): void
    {
        $old = $this->makeSubtitle([[10, 12, "The bakery opens at six."]]);
        $new = $this->makeSubtitle([[100, 102, "The bakery opens at six."]]);

        $this->assertSame([[CueDifferenceKind::TimingChanged, 0, 0]], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testUnrelatedCuesListRemovedBeforeAdded(): void
    {
        $old = $this->makeSubtitle([[1, 2, "Rain at noon."], [5, 6, "The harbour is closed."], [9, 10, "Goodbye."]]);
        $new = $this->makeSubtitle([[1, 2, "Rain at noon."], [7, 8, "Coffee is free today."], [9, 10, "Goodbye."]]);

        $this->assertSame([
            [CueDifferenceKind::Removed, 1, null],
            [CueDifferenceKind::Added, null, 1],
        ], $this->summarize(SubtitleDiff::compare($old, $new)));
    }


    public function testShortOverlapWithNeighbourDoesNotPair(): void
    {
        $old = $this->makeSubtitle([[1, 3, "The train leaves now."]]);
        $new = $this->makeSubtitle([[2.9, 5, "A different line."]]);

        $this->assertSame([
            [CueDifferenceKind::Removed, 0, null],
            [CueDifferenceKind::Added, null, 0],
        ], $this->summarize(SubtitleDiff::compare($old, $new)));
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
        $this->assertSame([CueDifferenceKind::TextChanged, 149, 149], $summary[149]);
        $this->assertSame([CueDifferenceKind::Removed, 150, null], $summary[150]);
        $this->assertSame([CueDifferenceKind::TextChanged, 151, 150], $summary[151]);
    }
}
