<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;

class RetimingTest extends \PHPUnit\Framework\TestCase
{
    private function makeSubtitle(array $times): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($times as $index => [$start, $end]) {
            $subtitle->addCue(new SubtitleCue($start, $end, "text$index"));
        }

        return $subtitle;
    }


    private function getTimes(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues());
    }


    public function testShiftMovesAllCues(): void
    {
        $subtitle = $this->makeSubtitle([[10, 12.5], [20.25, 22]]);

        $this->assertSame($subtitle, $subtitle->shift(-2.5));
        $this->assertSame([[7.5, 10.0], [17.75, 19.5]], $this->getTimes($subtitle));
    }


    public function testShiftOnlyMovesCuesFromGivenTime(): void
    {
        $subtitle = $this->makeSubtitle([[10, 12.5], [20, 22], [30, 31]]);

        $subtitle->shift(1.25, 20);

        $this->assertSame([[10.0, 12.5], [21.25, 23.25], [31.25, 32.25]], $this->getTimes($subtitle));
    }


    public function testShiftClampsNegativeTimesToZeroAndKeepsCues(): void
    {
        $subtitle = $this->makeSubtitle([[0.5, 1.5], [1, 3], [5, 6]]);

        $subtitle->shift(-2);

        $this->assertSame([[0.0, 0.0], [0.0, 1.0], [3.0, 4.0]], $this->getTimes($subtitle));
        $this->assertSame("text0", $subtitle->getCues()[0]->getText());
    }


    public function testScale(): void
    {
        $subtitle = $this->makeSubtitle([[1.5, 2.25]]);

        $this->assertSame($subtitle, $subtitle->scale(2));
        $this->assertSame([[3.0, 4.5]], $this->getTimes($subtitle));
    }


    public function testScaleByZeroThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The scale factor must be greater than 0, got 0.");
        $this->makeSubtitle([[1, 2]])->scale(0);
    }


    public function testConvertFrameRateFromPalToFilm(): void
    {
        $subtitle = $this->makeSubtitle([[100, 102.5]]);

        $this->assertSame($subtitle, $subtitle->convertFrameRate(25, 23.976));
        $this->assertSame([[104.271, 106.878]], $this->getTimes($subtitle));
    }


    public function testConvertFrameRateWithZeroFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The frame rate must be greater than 0, got 0.");
        $this->makeSubtitle([[1, 2]])->convertFrameRate(25, 0);
    }


    public function testSyncByTwoPoints(): void
    {
        $subtitle = $this->makeSubtitle([[10, 12], [3130, 3132], [6260, 6262]]);

        $this->assertSame($subtitle, $subtitle->syncByTwoPoints(10, 12, 6260, 6005));
        $this->assertSame(
            [[12.0, 13.918], [3003.706, 3005.623], [6005.0, 6006.918]],
            $this->getTimes($subtitle)
        );
    }


    public function testSyncByTwoPointsClampsNegativeTimesToZero(): void
    {
        $subtitle = $this->makeSubtitle([[1, 2], [10, 11]]);

        $subtitle->syncByTwoPoints(10, 5, 20, 15);

        $this->assertSame([[0.0, 0.0], [5.0, 6.0]], $this->getTimes($subtitle));
    }


    public function testSyncByTwoPointsWithEqualOldTimesThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The two old times must differ, got 10 twice.");
        $this->makeSubtitle([[1, 2]])->syncByTwoPoints(10, 12, 10, 15);
    }


    public function testSyncByTwoPointsWithReversedOrderThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The two new times must be in the same order as the two old times.");
        $this->makeSubtitle([[1, 2]])->syncByTwoPoints(10, 20, 30, 15);
    }
}
