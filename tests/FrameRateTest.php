<?php

namespace SubtitleToolbox;

use InvalidArgumentException;

class FrameRateTest extends \PHPUnit\Framework\TestCase
{
    public function testFramesToSeconds(): void
    {
        $this->assertSame(10.0, (new FrameRate(25))->framesToSeconds(250));
        $this->assertSame(41.708, round((new FrameRate(23.976))->framesToSeconds(1000), 3));
    }


    public function testSecondsToFramesRoundsToNearestFrame(): void
    {
        $this->assertSame(250, (new FrameRate(25))->secondsToFrames(10.0));
        $this->assertSame(1000, (new FrameRate(23.976))->secondsToFrames(41.708));
        $this->assertSame(3, (new FrameRate(25))->secondsToFrames(0.1));
    }


    public function testGetFps(): void
    {
        $this->assertSame(23.976, (new FrameRate(23.976))->getFps());
    }


    public function testZeroFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The frame rate must be greater than 0, got 0.");
        new FrameRate(0);
    }


    public function testNegativeFpsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FrameRate(-25);
    }
}
