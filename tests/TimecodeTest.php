<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Parsers\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SubtitleParser;

class TimecodeTest extends TestCase
{
    private const SECONDS = [0, 0.0004, 0.0005, 1.996, 59.9996, 3599.9996, 360000];


    /**
     * @return array<string, array{string, list<list<int>>}>
     */
    public static function units(): array
    {
        return [
            "seconds"      => ["seconds", [[0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 2], [0, 1, 0], [1, 0, 0], [100, 0, 0]]],
            "centiseconds" => ["centiseconds", [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
            "milliseconds" => ["milliseconds", [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 1], [0, 0, 1, 996], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
        ];
    }


    #[DataProvider("units")]
    public function testUnitRoundsTheTotalBeforeItSplits(string $method, array $expected): void
    {
        foreach (self::SECONDS as $index => $seconds) {
            $this->assertSame($expected[$index], Timecode::$method($seconds), "$method($seconds)");
        }
    }


    /**
     * @return array<string, array{float, bool, list<list<int>>}>
     */
    public static function frameRates(): array
    {
        return [
            "23.976 fps"            => [24000 / 1001, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 0, 59, 23], [0, 59, 56, 10], [99, 54, 0, 9]]],
            "25 fps"                => [25, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
            "29.97 fps drop-frame"  => [30000 / 1001, true, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 0, 59, 28], [1, 0, 0, 0], [100, 0, 0, 11]]],
            "30 fps"                => [30, false, [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 2, 0], [0, 1, 0, 0], [1, 0, 0, 0], [100, 0, 0, 0]]],
        ];
    }


    #[DataProvider("frameRates")]
    public function testFramesRoundToTheNearestFrameBeforeTheySplit(float $fps, bool $dropFrame, array $expected): void
    {
        foreach (self::SECONDS as $index => $seconds) {
            $this->assertSame($expected[$index], Timecode::frames($seconds, new FrameRate($fps), $dropFrame), "frames($seconds)");
        }
    }


    public function testDropFrameSkipsTheFirstLabelsOfEachMinuteExceptEveryTenthMinute(): void
    {
        $ntsc = new FrameRate(30000 / 1001);
        $this->assertSame([0, 0, 59, 29], Timecode::frameNumber(1799, $ntsc, true));
        $this->assertSame([0, 1, 0, 2], Timecode::frameNumber(1800, $ntsc, true));
        $this->assertSame([0, 9, 59, 29], Timecode::frameNumber(17981, $ntsc, true));
        $this->assertSame([0, 10, 0, 0], Timecode::frameNumber(17982, $ntsc, true));
        $this->assertSame([0, 11, 0, 2], Timecode::frameNumber(19782, $ntsc, true));
        $this->assertSame([0, 1, 0, 4], Timecode::frameNumber(3600, new FrameRate(60000 / 1001), true));
        $this->assertSame([0, 1, 0, 0], Timecode::frameNumber(1800, $ntsc));
    }


    public function testClockSecondsAndFramesRestartTheFrameCountAtEachClockSecond(): void
    {
        $film = new FrameRate(24000 / 1001);
        $this->assertSame([0, 1, 40, 0], Timecode::clockSecondsAndFrames(100, $film));
        $this->assertSame([0, 0, 2, 0], Timecode::clockSecondsAndFrames(1.996, $film));
        $this->assertSame([0, 0, 1, 12], Timecode::clockSecondsAndFrames(1.5, $film));
        $this->assertSame([0, 1, 0, 0], Timecode::clockSecondsAndFrames(59.9996, $film));
        foreach (self::SECONDS as $seconds) {
            $this->assertSame(Timecode::frames($seconds, new FrameRate(25)), Timecode::clockSecondsAndFrames($seconds, new FrameRate(25)));
        }
    }


    /**
     * @return array<string, array{string, float, array<string, mixed>, bool, 4?: SubtitleParser}>
     */
    public static function textFormats(): array
    {
        return [
            "ass"                => ["ass", 0.01, [], true],
            "csv"                => ["csv", 0.001, [], true],
            "csv frames"         => ["csv", 1 / 25, [CsvFormatter::OPTION_TIME_FORMAT => "hh:mm:ss:ff", CsvFormatter::OPTION_FRAME_RATE => 25], true, new CsvParser(new CsvColumns(frameRate: 25))],
            "ffmeta"             => ["ffmeta", 0.001, [], true],
            "html"               => ["html", 1, [], false],
            "itt"                => ["itt", 1 / 25, [IttFormatter::OPTION_FRAME_RATE => 25], true],
            "json"               => ["json", 0.001, [], true],
            "lrc"                => ["lrc", 0.01, [], false],
            "microdvd"           => ["microdvd", 1 / 25, [MicroDvdFormatter::OPTION_FRAME_RATE => 25], true, new MicroDvdParser(25)],
            "mpl2"               => ["mpl2", 0.1, [], true],
            "mpsub"              => ["mpsub", 0.001, [], true],
            "ogm"                => ["ogm", 0.001, [], false],
            "podcast"            => ["podcast", 0.001, [], true],
            "podcast-transcript" => ["podcast-transcript", 0.001, [], true, new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_KEEP_SEGMENTS => true])],
            "sami"               => ["sami", 0.001, [], true],
            "sbv"                => ["sbv", 0.001, [], true],
            "scc"                => ["scc", 1001 / 30000, [], true],
            "srt"                => ["srt", 0.001, [], true],
            "subviewer"          => ["subviewer", 0.01, [], true],
            "tmplayer"           => ["tmplayer", 1, [], false],
            "tsv"                => ["tsv", 0.001, [], true],
            "ttml"               => ["ttml", 0.001, [], true],
            "vtt"                => ["vtt", 0.001, [], true],
            "ytchapter"          => ["ytchapter", 1, [], false],
        ];
    }


    #[DataProvider("textFormats")]
    public function testCueAtTheEndOfASecondParsesBackWithinOneUnit(string $format, float $unit, array $options, bool $writesEnd, ?SubtitleParser $parser = null): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1.996, 4, "One"));
        $subtitle->addCue(new SubtitleCue(59.9996, 62, "Two"));

        $output = $subtitle->toString(Format::from($format), $options);
        $parsed = array_values(($parser?->parse($output) ?? Subtitle::fromString($output, Format::from($format)))->getCues());

        $this->assertCount(2, $parsed, $output);
        foreach ([[1.996, 4], [59.9996, 62]] as $index => [$start, $end]) {
            $this->assertEqualsWithDelta($start, $parsed[$index]->getStart(), $unit, $output);
            if ($writesEnd) {
                $this->assertEqualsWithDelta($end, $parsed[$index]->getEnd(), $unit, $output);
            }
        }
    }
}
