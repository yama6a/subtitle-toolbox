<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class ShotChangesTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/shot-changes/";


    public function testFromFfmpegLogReadsThePtsTimes(): void
    {
        $times = ShotChanges::fromFfmpegLog(file_get_contents(self::FILES . "own_ffmpeg_showinfo.log"));

        $this->assertSame([12.5, 62.5, 70.0, 80.0, 91.25], $times);
    }


    public function testFromFfmpegLogSortsAndDropsDuplicates(): void
    {
        $log = "[Parsed_showinfo_1 @ 0x1] n:   1 pts:  2 pts_time:8.5 duration:1\n" .
               "[Parsed_showinfo_1 @ 0x1] n:   0 pts:  1 pts_time:3 duration:1\n" .
               "[Parsed_showinfo_1 @ 0x1] n:   2 pts:  2 pts_time:8.5 duration:1\n";

        $this->assertSame([3.0, 8.5], ShotChanges::fromFfmpegLog($log));
        $this->assertSame([], ShotChanges::fromFfmpegLog("frame=    0 fps=0.0 time=00:00:10.00\n"));
    }


    public function testFromTextReadsSecondsAndTimestamps(): void
    {
        $times = ShotChanges::fromText(file_get_contents(self::FILES . "own_scenes.txt"));

        $this->assertSame([12.5, 62.5, 70.0, 80.0, 91.25], $times);
    }


    public function testFromTextSkipsBlankLinesAndReadsBomAndCrLf(): void
    {
        $text = "\xEF\xBB\xBF01:00:00.040\r\n\r\n  2  \r\n00:00:01\r\n";

        $this->assertSame([1.0, 2.0, 3600.04], ShotChanges::fromText($text));
    }


    public function testFromTextThrowsWithTheLineNumber(): void
    {
        try {
            ShotChanges::fromText("1.5\n\n00:01:2x\n");
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame(3, $exception->getLineNumber());
        }
    }
}
