<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class StartAfterEndTest extends TestCase
{
    private const SUBVIEWER_1 = "[INFORMATION]\n[TITLE]Harbour walk\n[END INFORMATION]\n" . SubViewerParser::START_SCRIPT . "\n";

    /**
     * Each case holds the format, the content, the line of the strict error, the cue times in lenient mode and the warning action.
     * Every file has one good cue from 10 s to 12 s after the broken cue.
     *
     * @return array<string, array{Format, string, ?int, list<array{float, float}>, ParseWarningAction}>
     */
    public static function brokenFiles(): array
    {
        $good  = [10.0, 12.0];
        $swap  = ParseWarningAction::Repaired;
        $drop  = ParseWarningAction::Skipped;

        return [
            "SubRip swapped"            => [Format::SubRip, "1\n00:00:05,000 --> 00:00:02,000\nHello\n\n2\n00:00:10,000 --> 00:00:12,000\nWorld\n", 2,
                                            [[2.0, 5.0], $good], $swap],
            "SubRip over 30 s"          => [Format::SubRip, "1\n00:01:05,000 --> 00:00:02,000\nHello\n\n2\n00:00:10,000 --> 00:00:12,000\nWorld\n", 2,
                                            [$good], $drop],
            "WebVTT fuzzed end"         => [Format::WebVtt, "WEBVTT\n\n00:00:05.000 --> 00:00:00.250\nHello\n\n00:00:10.000 --> 00:00:12.000\nWorld\n", 3,
                                            [[0.25, 5.0], $good], $swap],
            "ASS fuzzed end"            => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" .
                                            "Dialogue: 0,0:00:05.00,0:00:00.25,Default,,0,0,0,,Hello\nDialogue: 0,0:00:10.00,0:00:12.00,Default,,0,0,0,,World\n", 3,
                                            [[0.25, 5.0], $good], $swap],
            "CSV with a cut-off row"    => [Format::Csv, "start,end,text\n00:00:10.000,00:00:12.000,World\n00:00:20.000,00\n", 3,
                                            [$good], $drop],
            "CSV with commas in times"  => [Format::Csv, "start,end,text\n00:00:01,000,00:00:02,000,Hello\n00:00:10.000,00:00:12.000,World\n", 2,
                                            [$good], $drop],
            "SBV"                       => [Format::Sbv, "0:00:05.000,0:00:02.000\nHello\n\n0:00:10.000,0:00:12.000\nWorld\n", 1,
                                            [[2.0, 5.0], $good], $swap],
            "SubViewer 2"               => [Format::SubViewer, "[INFORMATION]\n[END INFORMATION]\n00:00:05.00,00:00:02.00\nHello\n\n00:00:10.00,00:00:12.00\nWorld\n", 3,
                                            [[2.0, 5.0], $good], $swap],
            "SubViewer 1"               => [Format::SubViewer, self::SUBVIEWER_1 . "[00:00:05]\nHello\n[00:00:02]\n[00:00:10]\nWorld\n[00:00:12]\n", 5,
                                            [[2.0, 5.0], $good], $swap],
            "MPL2"                      => [Format::Mpl2, "[50][20]Hello\n[100][120]World\n", 1,
                                            [[2.0, 5.0], $good], $swap],
            "MicroDVD"                  => [Format::MicroDvd, "{1}{1}25\n{125}{50}Hello\n{250}{300}World\n", 2,
                                            [[2.0, 5.0], $good], $swap],
            "Podcast transcript"        => [Format::PodcastTranscript, '{"version": "1.0.0", "segments": [' .
                                            '{"startTime": 5, "endTime": 2, "body": "Hello."}, {"startTime": 10, "endTime": 12, "body": "World."}]}', null,
                                            [[2.0, 5.0], $good], $swap],
        ];
    }


    #[DataProvider("brokenFiles")]
    public function testStrictModeThrowsWithTheLineNumber(Format $format, string $content, ?int $line): void
    {
        try {
            Subtitle::fromString($content, $format);
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertStringContainsString("before it starts at", $exception->getMessage());
            $this->assertSame($line, $exception->getLineNumber());
        }
    }


    /**
     * @param list<array{float, float}> $times
     */
    #[DataProvider("brokenFiles")]
    public function testLenientModeSwapsOrDropsTheCueAndWarns(Format $format, string $content, ?int $line, array $times, ParseWarningAction $action): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));

        $this->assertSame($times, array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues()));
        $warnings = $subtitle->getParseWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame($action, $warnings[0]->action);
        $this->assertSame($line, $warnings[0]->lineNumber);
        $this->assertStringContainsString("before it starts at", $warnings[0]->message);
    }


    public function testTheMessageNamesBothTimes(): void
    {
        $this->expectExceptionMessage("The cue ends at 2 s, before it starts at 5 s. (line 2)");

        Subtitle::fromString("1\n00:00:05,000 --> 00:00:02,000\nHello\n", Format::SubRip);
    }


    public function testACueThatEndsWhenItStartsIsValid(): void
    {
        $subtitle = Subtitle::fromString("1\n00:00:02,000 --> 00:00:02,000\nHello\n", Format::SubRip, new ReadOptions(lenient: true));

        $this->assertSame([2.0, 2.0], [$subtitle->getCues()[0]->getStart(), $subtitle->getCues()[0]->getEnd()]);
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    /**
     * @return array<string, array{Format, string}>
     */
    public static function streamedFiles(): array
    {
        $files = self::brokenFiles();

        return [
            "SubRip swapped"    => [new SubRipStreamReader(new ReadOptions(lenient: true)), Format::SubRip, $files["SubRip swapped"][1]],
            "SubRip over 30 s"  => [new SubRipStreamReader(new ReadOptions(lenient: true)), Format::SubRip, $files["SubRip over 30 s"][1]],
            "WebVTT fuzzed end" => [new WebVttStreamReader(new ReadOptions(lenient: true)), Format::WebVtt, $files["WebVTT fuzzed end"][1]],
        ];
    }


    #[DataProvider("streamedFiles")]
    public function testStreamReadersGiveTheCuesAndWarningsOfTheParser(SubRipStreamReader|WebVttStreamReader $reader, Format $format, string $content): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $stream   = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        $this->assertEquals($subtitle->getCues(), iterator_to_array($reader->read($stream)));
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
    }
}
