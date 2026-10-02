<?php

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\AssFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;

class DualSubtitleTest extends TestCase
{
    private const DIR = __DIR__ . "/files/dual/";


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
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getText(), $cue->getAlignment()],
            $subtitle->getCues()
        );
    }


    private function parseFixture(string $fileName): Subtitle
    {
        return Subtitle::parse(file_get_contents(self::DIR . $fileName), SubRipParser::class);
    }


    public function testFixturesParse(): void
    {
        $english = $this->parseFixture("station_en.srt");
        $german  = $this->parseFixture("station_de.srt");

        $this->assertCount(5, $english->getCues());
        $this->assertSame([1.0, 4.0, "The train to Hamburg leaves from platform 4.", null],
                          $this->describeCues($english)[0]);
        $this->assertSame([16.0, 19.0, "Take an umbrella with you.", null], $this->describeCues($english)[4]);

        $this->assertCount(6, $german->getCues());
        $this->assertSame([1.2, 3.9, "Der Zug nach Hamburg fährt von Gleis 4.", null], $this->describeCues($german)[0]);
        $this->assertSame([19.2, 21.0, "Gute Reise!", null], $this->describeCues($german)[5]);
    }


    public function testFixturesRoundTrip(): void
    {
        foreach (["station_en.srt", "station_de.srt"] as $fileName) {
            $subtitle = $this->parseFixture($fileName);
            $again    = Subtitle::parse($subtitle->format(SubRipFormatter::class), SubRipParser::class);

            $this->assertSame($this->describeCues($subtitle), $this->describeCues($again), $fileName);
        }
    }


    public static function provideExpectedFiles(): array
    {
        $stack     = new DualSubtitleOptions(secondaryStyle: "i");
        $topBottom = new DualSubtitleOptions(mode: DualSubtitleOptions::MODE_TOP_BOTTOM,
                                             secondaryStyle: 'font color="#ffff00"');

        return [
            "stack SubRip"          => [$stack, SubRipFormatter::class, "station_stack.srt"],
            "stack WebVTT"          => [$stack, WebVttFormatter::class, "station_stack.vtt"],
            "stack ASS"             => [$stack, AssFormatter::class, "station_stack.ass"],
            "top and bottom SubRip" => [$topBottom, SubRipFormatter::class, "station_top_bottom.srt"],
            "top and bottom WebVTT" => [$topBottom, WebVttFormatter::class, "station_top_bottom.vtt"],
            "top and bottom ASS"    => [$topBottom, AssFormatter::class, "station_top_bottom.ass"],
        ];
    }


    #[DataProvider("provideExpectedFiles")]
    public function testMergeFixturesMatchesExpectedFile(DualSubtitleOptions $options, string $formatter,
                                                         string $expectedFile): void
    {
        $dual = DualSubtitle::merge($this->parseFixture("station_en.srt"), $this->parseFixture("station_de.srt"),
                                    $options);

        $this->assertSame(file_get_contents(self::DIR . $expectedFile), $dual->format($formatter));
    }


    public function testMergeChangesNeitherInput(): void
    {
        $english       = $this->parseFixture("station_en.srt");
        $german        = $this->parseFixture("station_de.srt");
        $englishBefore = $this->describeCues($english);
        $germanBefore  = $this->describeCues($german);

        DualSubtitle::merge($english, $german, new DualSubtitleOptions(secondaryStyle: "i"));
        DualSubtitle::merge($english, $german, new DualSubtitleOptions(mode: DualSubtitleOptions::MODE_TOP_BOTTOM));

        $this->assertSame($englishBefore, $this->describeCues($english));
        $this->assertSame($germanBefore, $this->describeCues($german));
    }


    public function testStackJoinsTheIssueExample(): void
    {
        $english = $this->makeSubtitle([[1, 4, "Where are you going?"]]);
        $german  = $this->makeSubtitle([[1.2, 3.9, "Wohin gehst du?"]]);

        $dual = DualSubtitle::merge($english, $german, new DualSubtitleOptions(secondaryStyle: "i"));

        $this->assertSame([[1.0, 4.0, "Where are you going?\n<i>Wohin gehst du?</i>", null]],
                          $this->describeCues($dual));
    }


    public function testStackPicksTheEarlierPrimaryCueOnEqualOverlap(): void
    {
        $primary   = $this->makeSubtitle([[0, 2, "first"], [2, 4, "second"]]);
        $secondary = $this->makeSubtitle([[1.5, 2.5, "between"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.5, "first\nbetween", null], [2.0, 4.0, "second", null]],
                          $this->describeCues($dual));
    }


    public function testStackKeepsTouchingCuesApart(): void
    {
        $primary   = $this->makeSubtitle([[0, 2, "first"]]);
        $secondary = $this->makeSubtitle([[2, 3, "after"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.0, "first", null], [2.0, 3.0, "after", null]], $this->describeCues($dual));
    }


    public function testTopBottomUsesTheAlignmentOfTheOptions(): void
    {
        $primary   = $this->makeSubtitle([[0, 2, "first"]]);
        $secondary = $this->makeSubtitle([[0, 2, "erste"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleOptions::MODE_TOP_BOTTOM,
            secondaryAlignment: 9,
        ));

        $this->assertSame([[0.0, 2.0, "first", null], [0.0, 2.0, "erste", 9]], $this->describeCues($dual));
    }


    public function testTopBottomSnapsUpToTheTolerance(): void
    {
        $primary   = $this->makeSubtitle([[1, 4, "first"]]);
        $secondary = $this->makeSubtitle([[1.25, 3.7, "erste"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleOptions::MODE_TOP_BOTTOM,
        ));
        $noSnap = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleOptions::MODE_TOP_BOTTOM,
            snapTolerance: 0,
        ));

        $this->assertSame([1.0, 3.7], [$dual->getCues()[1]->getStart(), $dual->getCues()[1]->getEnd()]);
        $this->assertSame([1.25, 3.7], [$noSnap->getCues()[1]->getStart(), $noSnap->getCues()[1]->getEnd()]);
    }


    public function testTopBottomKeepsTimesWhenSnappingWouldHideTheCue(): void
    {
        $primary   = $this->makeSubtitle([[1, 4, "first"]]);
        $secondary = $this->makeSubtitle([[3.9, 4.1, "kurz"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions(
            mode: DualSubtitleOptions::MODE_TOP_BOTTOM,
        ));

        $this->assertSame([3.9, 4.1, "kurz", 8], $this->describeCues($dual)[1]);
    }


    public function testMetadataCommentsAndFormatDataComeFromThePrimary(): void
    {
        $primary = $this->makeSubtitle([[0, 2, "first"], [3, 5, "second"]])
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setMetadata(Subtitle::METADATA_TITLE, "Station")
            ->setFormatData("vtt", ["header" => "Kind: captions"])
            ->addComment("before second", 1)
            ->addComment("at the end", 2);
        $secondary = $this->makeSubtitle([[0.5, 1, "vorher"], [2.2, 2.8, "dazwischen"]])
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "de")
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Somebody")
            ->setFormatData("ass", ["styles" => []])
            ->addComment("secondary comment", 0);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame(["language" => "en+de", "title" => "Station"], $dual->getAllMetadata());
        $this->assertSame(["header" => "Kind: captions"], $dual->getFormatData("vtt"));
        $this->assertSame([], $dual->getFormatData("ass"));
        $this->assertSame([["text" => "before second", "beforeCueIndex" => 2],
                           ["text" => "at the end", "beforeCueIndex" => 3]], $dual->getComments());
        $this->assertSame([[0.0, 2.0, "first\nvorher", null], [2.2, 2.8, "dazwischen", null], [3.0, 5.0, "second", null]],
                          $this->describeCues($dual));
    }


    public function testLanguageStaysWhenOnlyThePrimaryHasOne(): void
    {
        $primary   = $this->makeSubtitle([[0, 2, "first"]])->setMetadata(Subtitle::METADATA_LANGUAGE, "en");
        $secondary = $this->makeSubtitle([[0, 2, "erste"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame("en", $dual->getMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testSecondaryCuesLoseIdentifierAndFormatData(): void
    {
        $primary = $this->makeSubtitle([[0, 2, "first"]]);
        $cue     = (new SubtitleCue(5, 6, "später"))->setIdentifier("7")->setFormatData("ass", ["style" => "Sign"]);
        $secondary = (new Subtitle())->addCue($cue);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertNull($dual->getCues()[1]->getIdentifier());
        $this->assertSame([], $dual->getCues()[1]->getFormatData("ass"));
    }


    public function testEmptyPrimaryKeepsItsCommentsAfterTheLastCue(): void
    {
        $primary   = (new Subtitle())->addComment("only a note", 0);
        $secondary = $this->makeSubtitle([[0, 2, "erste"]]);

        $dual = DualSubtitle::merge($primary, $secondary, new DualSubtitleOptions());

        $this->assertSame([[0.0, 2.0, "erste", null]], $this->describeCues($dual));
        $this->assertSame([["text" => "only a note", "beforeCueIndex" => 1]], $dual->getComments());
        $this->assertSame([], $primary->getCues());
    }


    public static function provideInvalidOptions(): array
    {
        return [
            "unknown mode"       => [["mode" => "sideBySide"]],
            "negative tolerance" => [["snapTolerance" => -0.1]],
            "unknown tag"        => [["secondaryStyle" => "c.yellow"]],
            "alignment 0"        => [["secondaryAlignment" => 0]],
            "alignment 10"       => [["secondaryAlignment" => 10]],
        ];
    }


    #[DataProvider("provideInvalidOptions")]
    public function testOptionsRejectInvalidValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DualSubtitleOptions(...$arguments);
    }
}
