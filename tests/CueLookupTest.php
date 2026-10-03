<?php

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\WebVttParser;

class CueLookupTest extends TestCase
{
    private function parseSigns(): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/files/ass/real/own_signs_crlf.ass"), AssParser::class);
    }


    private function parseHarbourTour(): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/files/editing/harbour_tour.vtt"), WebVttParser::class);
    }


    private function makeSubtitle(array $times, bool $reIndex = true): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($times as $index => [$start, $end]) {
            $subtitle->addCue(new SubtitleCue($start, $end, "text$index"), $reIndex);
        }

        return $subtitle;
    }


    /**
     * A cue every 2.5 s for 2 s, a 30 s sign every 100 cues and one cue from start to end, 10,101 cues in total.
     */
    private function makeLargeSubtitle(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(0, 25000, "background"), false);
        for ($cue = 0; $cue < 10000; $cue++) {
            $subtitle->addCue(new SubtitleCue($cue * 2.5, $cue * 2.5 + 2, "line $cue"), false);
            if ($cue % 100 === 0) {
                $subtitle->addCue(new SubtitleCue($cue * 2.5 + 1, $cue * 2.5 + 31, "sign $cue"), false);
            }
        }

        return $subtitle->reIndexCues();
    }


    private function getTexts(array $cues): array
    {
        return array_values(array_map(fn (SubtitleCue $cue): string => $cue->getText(), $cues));
    }


    private function scanCuesAt(Subtitle $subtitle, float $time): array
    {
        return array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool =>
            $cue->getStart() <= $time && $time < $cue->getEnd());
    }


    public function testCountAndIterateRealFile(): void
    {
        $subtitle = $this->parseSigns();

        $this->assertCount(7, $subtitle);
        $this->assertSame(7, count($subtitle));
        $this->assertSame($subtitle->getCues(), iterator_to_array($subtitle));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_keys(iterator_to_array($subtitle)));
    }


    public function testIterateYieldsIndexOrderAfterRemovalWithoutReIndex(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2], [3, 4], [5, 6]])->removeCue(1, false);

        $indexes = [];
        foreach ($subtitle as $index => $cue) {
            $indexes[$index] = $cue->getText();
        }

        $this->assertSame([0 => "text0", 2 => "text2"], $indexes);
        $this->assertCount(2, $subtitle);
    }


    public function testCountEmptySubtitle(): void
    {
        $subtitle = new Subtitle();

        $this->assertCount(0, $subtitle);
        $this->assertSame([], $subtitle->getCuesAt(1));
        $this->assertNull($subtitle->getCueIndexAt(1));
        $this->assertSame([], $subtitle->getCuesBetween(0, 10));
    }


    public function testGetCuesAtFindsOverlappingCuesInRealFile(): void
    {
        $subtitle = $this->parseSigns();

        $this->assertSame([1, 2, 3], array_keys($subtitle->getCuesAt(5)));
        $this->assertSame([2, 3, 4], array_keys($subtitle->getCuesAt(8.5)));
        $this->assertSame("SUNNY\n25 °C", $subtitle->getCuesAt(8.5)[2]->getText());
        $this->assertSame(2, $subtitle->getCueIndexAt(8.5));
    }


    public function testCueIsOnScreenFromStartUntilBeforeEnd(): void
    {
        $subtitle = $this->parseHarbourTour();

        $this->assertSame([0], array_keys($subtitle->getCuesAt(1)));
        $this->assertSame([1], array_keys($subtitle->getCuesAt(4)));
        $this->assertSame([], $subtitle->getCuesAt(0.999));
        $this->assertSame([], $subtitle->getCuesAt(6.2));
        $this->assertNull($subtitle->getCueIndexAt(6.2));
        $this->assertNull($subtitle->getCueIndexAt(20));
        $this->assertSame(5, $subtitle->getCueIndexAt(19.999));
    }


    public function testZeroLengthCueIsNeverOnScreen(): void
    {
        $subtitle = $this->makeSubtitle([[1, 1], [1, 2]]);

        $this->assertSame([1], array_keys($subtitle->getCuesAt(1)));
    }


    public function testGetCuesBetweenReturnsOverlappingCuesUncut(): void
    {
        $subtitle = $this->parseHarbourTour();
        $cues     = $subtitle->getCuesBetween(5, 13);

        $this->assertSame([1, 2], array_keys($cues));
        $this->assertSame($subtitle->getCues()[1], $cues[1]);
        $this->assertSame(4.0, $cues[1]->getStart());
        $this->assertSame(12.5, $cues[2]->getEnd());
        $this->assertSame([3, 4], array_keys($subtitle->getCuesBetween(13, 17)));
        $this->assertSame([], $subtitle->getCuesBetween(17, 18));
    }


    public function testGetCuesBetweenRejectsReversedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The range start 10 must not be after the range end 5.");

        (new Subtitle())->getCuesBetween(10, 5);
    }


    public function testCuesOutOfOrderUseLinearScan(): void
    {
        $subtitle = $this->makeSubtitle([[5, 9], [1, 4], [3, 6]], false);

        $this->assertSame([0, 2], array_keys($subtitle->getCuesAt(5)));
        $this->assertSame(1, $subtitle->getCueIndexAt(3.5));
        $this->assertSame([1, 2], array_keys($subtitle->getCuesBetween(2, 5)));
    }


    public function testLookupSeesTimeChangesOfCues(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->getCueIndexAt(2));

        $subtitle->shift(10);
        $this->assertNull($subtitle->getCueIndexAt(2));
        $this->assertSame(0, $subtitle->getCueIndexAt(12));

        $subtitle->getCues()[5]->setStart(1)->setEnd(3);
        $this->assertSame(5, $subtitle->getCueIndexAt(2));

        $subtitle->removeCue(5);
        $this->assertNull($subtitle->getCueIndexAt(2));
    }


    public function testLookupSeesSetStartAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->getCueIndexAt(2));
        $this->assertSame([0, 1], array_keys($subtitle->getCuesBetween(1, 5)));

        $subtitle->getCues()[0]->setStart(2.5);

        $this->assertNull($subtitle->getCueIndexAt(2));
        $this->assertSame(0, $subtitle->getCueIndexAt(2.5));
        $this->assertSame([], $subtitle->getCuesBetween(1, 2.5));
        $this->assertSame([0], array_keys($subtitle->getCuesBetween(1, 2.6)));
    }


    public function testLookupSeesSetStartOnLargeSubtitle(): void
    {
        $subtitle = $this->makeLargeSubtitle();
        $this->assertSame(["background", "line 4000", "sign 4000"], $this->getTexts($subtitle->getCuesAt(10001.5)));

        $sign = array_key_first($subtitle->findCues(fn (SubtitleCue $cue): bool => $cue->getText() === "sign 4000"));
        $subtitle->getCues()[$sign]->setStart(10001.6);

        $this->assertSame(["background", "line 4000"], $this->getTexts($subtitle->getCuesAt(10001.5)));
        $this->assertSame(["background", "line 4000", "sign 4000"], $this->getTexts($subtitle->getCuesAt(10001.6)));
    }


    public function testLookupSeesCueAddedAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertNull($subtitle->getCueIndexAt(30));

        $subtitle->addCue(new SubtitleCue(29, 31, "late"), false);

        $this->assertSame(6, $subtitle->getCueIndexAt(30));
    }


    public function testLookupSeesCueRemovedAfterLookup(): void
    {
        $subtitle = $this->parseHarbourTour();
        $this->assertSame(0, $subtitle->getCueIndexAt(2));

        $subtitle->removeCue(0, false);

        $this->assertNull($subtitle->getCueIndexAt(2));
        $this->assertSame(1, $subtitle->getCueIndexAt(5));
    }


    public function testLookupOnLargeSubtitleMatchesLinearScan(): void
    {
        $subtitle = $this->makeLargeSubtitle();

        $this->assertCount(10101, $subtitle);
        $this->assertSame(["background", "line 4000", "sign 4000"], $this->getTexts($subtitle->getCuesAt(10001.5)));
        $this->assertSame(["background", "sign 4000", "line 4011"], $this->getTexts($subtitle->getCuesAt(10027.5)));
        $this->assertSame(["background", "line 9999"], $this->getTexts($subtitle->getCuesAt(24997.5)));
        $this->assertSame(["background"], $this->getTexts($subtitle->getCuesAt(24999.9)));
        $this->assertSame([], $subtitle->getCuesAt(25000));

        mt_srand(81);
        for ($sample = 0; $sample < 200; $sample++) {
            $time = mt_rand(-100, 2510000) / 100;
            $this->assertSame(array_keys($this->scanCuesAt($subtitle, $time)), array_keys($subtitle->getCuesAt($time)));
        }
    }


    public function testGetCuesBetweenOnLargeSubtitle(): void
    {
        $subtitle = $this->makeLargeSubtitle();

        $this->assertSame(
            ["background", "line 3999", "line 4000", "sign 4000"],
            $this->getTexts($subtitle->getCuesBetween(9999, 10002.5))
        );
    }


    public function testFindCuesKeepsIndexes(): void
    {
        $subtitle = $this->parseHarbourTour();

        $cues = $subtitle->findCues(fn (SubtitleCue $cue): bool => str_contains($cue->getText(), "harbour"));

        $this->assertSame([0, 1], array_keys($cues));
        $this->assertCount(6, $subtitle);
    }


    public function testFilterCuesRemovesCuesAndKeepsComments(): void
    {
        $subtitle = $this->parseHarbourTour();

        $result = $subtitle->filterCues(fn (SubtitleCue $cue): bool => !str_contains($cue->getText(), "Guide"));

        $this->assertSame($subtitle, $result);
        $this->assertSame([0, 1, 2, 3], array_keys($subtitle->getCues()));
        $this->assertSame(
            [
                ["text" => "Recorded on the north pier", "beforeCueIndex" => 0],
                ["text" => "Segment two starts here", "beforeCueIndex" => 1],
                ["text" => "The guide speaks from here", "beforeCueIndex" => 3],
                ["text" => "The boat turns left here", "beforeCueIndex" => 3],
                ["text" => "End of recording", "beforeCueIndex" => 4],
            ],
            $subtitle->getComments()
        );
        $this->assertStringContainsString(
            "NOTE The boat turns left here\n\n4\n00:00:18.000 --> 00:00:20.000",
            $subtitle->format(WebVttFormatter::class)
        );
    }


    public function testFilterCuesByDuration(): void
    {
        $subtitle = $this->parseSigns();

        $subtitle->filterCues(fn (SubtitleCue $cue): bool => $cue->getEnd() - $cue->getStart() >= 3.5);

        $this->assertSame([[5.0, 9.0], [5.0, 9.0]], array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()],
            $subtitle->getCues()
        ));
    }

}
