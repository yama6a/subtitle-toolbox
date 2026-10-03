<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

class TmPlayerParserTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/tmplayer/";


    /**
     * Each case holds the file, the cue count, the first and the last cue, and the output options of a byte-for-byte
     * round trip, or null when the formatter writes the file in another shape.
     */
    public static function realFiles(): array
    {
        return [
            "TMPlayer with CR LF" => [
                "tmplayer_crlf.txt",
                5,
                [1.0, 5.0, "The train to the coast\nleaves from platform two."],
                [3600.0, 3605.0, "We arrive on time today."],
                new WriteOptions(lineEnding: LineEnding::Crlf),
            ],
            "TMPlayer+ with equals signs" => [
                "tmplayer_plus.txt",
                4,
                [2.0, 6.0, "Rain moves in from the west."],
                [150.0, 155.0, "Frost on the roads in the morning."],
                null,
            ],
            "TMPlayer+ with line numbers and BOM" => [
                "tmplayer_multiline_bom.txt",
                3,
                [3.0, 7.0, "The shop opens at eight.\nFresh milk arrives at nine."],
                [12.0, 16.0, "Bring your own bag.\nPaper bags cost ten cents."],
                null,
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($file)->getCues();

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, $this->row($cues[0]));
        $this->assertSame($lastCue, $this->row($cues[$cueCount - 1]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $file, int $cueCount, array $firstCue, array $lastCue, ?WriteOptions $options): void
    {
        // The formatter writes no end entry after the last cue. tmplayer_multiline_bom.txt has one 4 s after the start.
        $readOptions = new ReadOptions(lastCueDuration: 4);
        $subtitle    = $this->parseFile($file, $readOptions);
        $formatted   = $subtitle->toString(Format::TmPlayer, $options ?? new WriteOptions());

        $this->assertEquals($subtitle->getCues(), (new TmPlayerParser())->parse($formatted, $readOptions)->getCues());
        if ($options !== null) {
            $this->assertSame(file_get_contents(self::DIR . "real/$file"), $formatted);
        }
    }


    public static function variants(): array
    {
        return [
            "colon"                 => ["00:00:01:Where are you?|Home.\n"],
            "equals sign"           => ["0:00:01=Where are you?|Home.\n"],
            "line numbers"          => ["00:00:01,1=Where are you?\n00:00:01,2=Home.\n"],
            "line numbers and colon" => ["00:00:01,1:Where are you?\n00:00:01,2:Home.\n"],
        ];
    }


    #[DataProvider("variants")]
    public function testReadsTheVariants(string $content): void
    {
        $cues = (new TmPlayerParser())->parse($content, new ReadOptions())->getCues();

        $this->assertSame([[1.0, 6.0, "Where are you?\nHome."]], array_map($this->row(...), $cues));
    }


    public function testCueEndsAtTheNextCue(): void
    {
        $cues = (new TmPlayerParser())->parse("00:00:01:One\n00:00:03:Two\n00:00:09:\n", new ReadOptions())->getCues();

        $this->assertSame([[1.0, 3.0, "One"], [3.0, 9.0, "Two"]], array_map($this->row(...), $cues));
    }


    public function testLastCueDurationIsAConstructorArgument(): void
    {
        $cue = (new TmPlayerParser())->parse("00:01:00:Bye", new ReadOptions(lastCueDuration: 2.5))->getCues()[0];

        $this->assertSame([60.0, 62.5, "Bye"], $this->row($cue));
    }


    public function testLineNumberOneStartsANewCueAtTheSameTime(): void
    {
        $cues = (new TmPlayerParser())->parse("00:00:01,1=One\n00:00:01,1=Two\n", new ReadOptions())->getCues();

        $this->assertSame([[1.0, 1.0, "One"], [1.0, 6.0, "Two"]], array_map($this->row(...), $cues));
    }


    public function testKeepsColonsAndEscapesTagCharacters(): void
    {
        $cue = (new TmPlayerParser())->parse("00:00:01:Time: 10:30 <now> & then", new ReadOptions())->getCues()[0];

        $this->assertSame("Time: 10:30 &lt;now&gt; &amp; then", $cue->getText());
    }


    public function testStrictModeThrowsWithTheLineNumber(): void
    {
        try {
            (new TmPlayerParser())->parse("00:00:02:Fine\nbroken line\n", new ReadOptions());
            $this->fail("The parser accepted a line without a time.");
        } catch (ParsingException $exception) {
            $this->assertSame(2, $exception->getLineNumber());
            $this->assertStringContainsString("Line 2 is not a TMPlayer line: broken line", $exception->getMessage());
        }
    }


    public function testLenientModeSkipsLinesWithoutATime(): void
    {
        $subtitle = (new TmPlayerParser())->parse(file_get_contents(self::DIR . "lenient/broken.txt"), new ReadOptions(lenient: true));

        $this->assertSame([[2.0, 9.0, "The museum opens at ten."], [9.0, 14.0, "The cafe is on the top floor."]], array_map($this->row(...), $subtitle->getCues()));
        $this->assertSame(
            [
                [1, 0, ParseWarning::SKIPPED, "Line 1 is not a TMPlayer line: Movie.Name.2004.DVDRip (line 1)"],
                [3, 2, ParseWarning::SKIPPED, "Line 3 is not a TMPlayer line: 00:0x:06:Entry is free on Mondays. (line 3)"],
            ],
            array_map(fn (ParseWarning $warning): array => [$warning->lineNumber, $warning->blockIndex, $warning->action, $warning->message], $subtitle->getParseWarnings())
        );
    }


    public function testSubtitleParseDetectsTmPlayer(): void
    {
        $this->assertCount(2, Subtitle::fromStringAutoDetectFormat("00:00:01:Where are you?\r\n00:00:04:Home.\r\n")->getCues());
    }


    private function parseFile(string $file, ReadOptions $options = new ReadOptions()): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::DIR . "real/$file"), Format::TmPlayer, $options);
    }


    private function row(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getText()];
    }
}
