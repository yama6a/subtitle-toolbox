<?php

declare(strict_types=1);

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
        $cues     = [];
        for ($index = 0; $index < $cueCount; $index++) {
            $time     += mt_rand(500, 4000) / 1000;
            $duration  = mt_rand(1000, 5000) / 1000;
            $cues[] = new SubtitleCue($time, $time + $duration, "text$index");
            $time     += $duration;
        }
        $subtitle->addCues($cues);

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

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference));

        $this->assertEqualsWithDelta(-2.3, $result->offset, 0.02);
        $this->assertEqualsWithDelta(25 / 23.976, $result->scale, 0.00001);
        $this->assertGreaterThan(0.8, $result->score);
        $this->assertNotSame($before, $this->getTimes($target));
    }


    public function testApplyMatchesExpectedFileAndReferenceTimes(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        ReferenceSync::apply($target, new ReferenceSyncOptions($reference));
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
        $result = ReferenceSync::apply($this->makeRandomSubtitle(300, 1), new ReferenceSyncOptions($this->makeRandomSubtitle(300, 2)));

        $this->assertLessThan(0.5, $result->score);
    }


    public function testScaleSearchOff(): void
    {
        $reference = $this->load("own_reference_en.srt");
        $target    = $this->load("own_target_de_25fps.srt");

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference, searchScale: false));

        $this->assertSame(1.0, $result->scale);
        $this->assertLessThan(0.5, $result->score);
    }


    public function testOffsetRangeOption(): void
    {
        $reference = $this->makeRandomSubtitle(200, 3);
        $target    = $this->makeRandomSubtitle(200, 3)->shift(75);

        $this->assertGreaterThanOrEqual(-60, ReferenceSync::apply(clone $target, new ReferenceSyncOptions($reference))->offset);

        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference, -90, -60));
        $this->assertSame(-75.0, $result->offset);
        $this->assertSame(1.0, $result->scale);
        $this->assertEqualsWithDelta(1, $result->score, 0.000001);
    }


    public function testEmptySubtitleScoresZero(): void
    {
        $result = ReferenceSync::apply(new Subtitle(), new ReferenceSyncOptions($this->makeRandomSubtitle(10, 4)));

        $this->assertSame([0.0, 1.0, 0.0], [$result->offset, $result->scale, $result->score]);
    }


    public function testSyncToAnIdenticalCopyKeepsTimes(): void
    {
        $subtitle = $this->makeRandomSubtitle(20, 5);
        $before   = $this->getTimes($subtitle);

        $result = ReferenceSync::apply($subtitle, new ReferenceSyncOptions(clone $subtitle));

        $this->assertSame([0.0, 1.0], [$result->offset, $result->scale]);
        $this->assertSame($before, $this->getTimes($subtitle));
    }


    public function testInvalidRangeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReferenceSyncOptions(new Subtitle(), 10, -10);
    }


    public function testTwoThousandCuesFinishUnderTwoSeconds(): void
    {
        $reference = $this->makeRandomSubtitle(2000, 6);
        $target    = $this->makeRandomSubtitle(2000, 6)->scale(23.976 / 25)->shift(12.4);

        $start  = microtime(true);
        $result = ReferenceSync::apply($target, new ReferenceSyncOptions($reference));

        $this->assertLessThan(2, microtime(true) - $start);
        $this->assertEqualsWithDelta(25 / 23.976, $result->scale, 0.00001);
        $this->assertEqualsWithDelta(-12.4 * 25 / 23.976, $result->offset, 0.02);
    }
}
