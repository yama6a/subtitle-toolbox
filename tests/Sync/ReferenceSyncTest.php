<?php

namespace SubtitleToolbox\Sync;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class ReferenceSyncTest extends TestCase
{
    private function load(string $name): Subtitle
    {
        return Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sync/$name"), Format::SubRip);
    }


    private function getTimes(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues());
    }


    private function makeRandomSubtitle(int $cueCount, int $seed): Subtitle
    {
        mt_srand($seed);
        $subtitle = new Subtitle();
        $time     = 1.0;
        for ($index = 0; $index < $cueCount; $index++) {
            $time     += mt_rand(500, 4000) / 1000;
            $duration  = mt_rand(1000, 5000) / 1000;
            $subtitle->addCue(new SubtitleCue($time, $time + $duration, "text$index"), false);
            $time     += $duration;
        }

        return $subtitle;
    }


    public function testRealFilesParse(): void
    {
        $reference = $this->load("own_reference_en.srt")->getCues();
        $target    = $this->load("own_target_de_25fps.srt")->getCues();

        $this->assertCount(60, $reference);
        $this->assertSame([77.689, 79.205, "The bakery opens at six every morning."],
                          [$reference[0]->getStart(), $reference[0]->getEnd(), $reference[0]->getText()]);
        $this->assertSame([800.651, 804.214, "Good morning, the usual please."],
                          [$reference[59]->getStart(), $reference[59]->getEnd(), $reference[59]->getText()]);

        $this->assertCount(59, $target);
        $this->assertSame([76.738, 78.148, "Die Bäckerei öffnet jeden Morgen um sechs."],
                          [$target[0]->getStart(), $target[0]->getEnd(), $target[0]->getText()]);
        $this->assertSame([770.055, 773.45, "Guten Morgen, wie immer bitte."],
                          [$target[58]->getStart(), $target[58]->getEnd(), $target[58]->getText()]);
    }


    public function testFindsOffsetAndFrameRateScaleWithMissingAndExtraCues(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");
        $before    = $this->getTimes($target);

        $result = ReferenceSync::sync($target, $reference);

        $this->assertEqualsWithDelta(-2.3, $result->getOffset(), 0.02);
        $this->assertEqualsWithDelta(25 / 23.976, $result->getScale(), 0.00001);
        $this->assertGreaterThan(0.8, $result->getScore());
        $this->assertSame($before, $this->getTimes($target));
    }


    public function testApplyMatchesExpectedFileAndReferenceTimes(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        $this->assertSame($target, ReferenceSync::sync($target, $reference)->apply($target));
        $this->assertSame(file_get_contents(__DIR__ . "/../files/sync/own_target_de_synced.srt"),
                          $target->toString(Format::SubRip));

        $referenceStarts = array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $reference->getCues());
        $matched         = 0;
        foreach ($target->getCues() as $cue) {
            $distance = min(array_map(fn (float $start): float => abs($start - $cue->getStart()), $referenceStarts));
            if ($distance < 1) {
                $this->assertLessThanOrEqual(0.05, $distance);
                $matched++;
            }
        }
        $this->assertSame(57, $matched);
    }


    public function testUnrelatedFilesScoreBelowHalf(): void
    {
        $result = ReferenceSync::sync($this->makeRandomSubtitle(300, 1), $this->makeRandomSubtitle(300, 2));

        $this->assertLessThan(0.5, $result->getScore());
    }


    public function testScaleSearchOff(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        $result = ReferenceSync::sync($target, $reference, new ReferenceSyncOptions(searchScale: false));

        $this->assertSame(1.0, $result->getScale());
        $this->assertLessThan(0.5, $result->getScore());
    }


    public function testOffsetRangeOption(): void
    {
        $reference = $this->makeRandomSubtitle(200, 3);
        $target    = $this->makeRandomSubtitle(200, 3)->shift(75);

        $this->assertGreaterThanOrEqual(-60, ReferenceSync::sync($target, $reference)->getOffset());

        $result = ReferenceSync::sync($target, $reference, new ReferenceSyncOptions(-90, -60));
        $this->assertSame(-75.0, $result->getOffset());
        $this->assertSame(1.0, $result->getScale());
        $this->assertEqualsWithDelta(1, $result->getScore(), 0.000001);
    }


    public function testEmptySubtitleScoresZero(): void
    {
        $result = ReferenceSync::sync(new Subtitle(), $this->makeRandomSubtitle(10, 4));

        $this->assertSame([0.0, 1.0, 0.0], [$result->getOffset(), $result->getScale(), $result->getScore()]);
    }


    public function testApplyWithoutChangeKeepsTimes(): void
    {
        $subtitle = $this->makeRandomSubtitle(20, 5);
        $before   = $this->getTimes($subtitle);

        (new SyncResult(0, 1, 1))->apply($subtitle);

        $this->assertSame($before, $this->getTimes($subtitle));
    }


    public function testInvalidRangeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReferenceSyncOptions(10, -10);
    }


    public function testTwoThousandCuesFinishUnderTwoSeconds(): void
    {
        $reference = $this->makeRandomSubtitle(2000, 6);
        $target    = $this->makeRandomSubtitle(2000, 6)->scale(23.976 / 25)->shift(12.4);

        $start  = microtime(true);
        $result = ReferenceSync::sync($target, $reference);

        $this->assertLessThan(2, microtime(true) - $start);
        $this->assertEqualsWithDelta(25 / 23.976, $result->getScale(), 0.00001);
        $this->assertEqualsWithDelta(-12.4 * 25 / 23.976, $result->getOffset(), 0.02);
    }
}
