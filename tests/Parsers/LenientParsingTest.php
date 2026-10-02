<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class LenientParsingTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/lenient/";

    private const SKIPPED  = ParseWarning::SKIPPED;
    private const REPAIRED = ParseWarning::REPAIRED;


    /**
     * Each case holds the parser, the message of the strict ParsingException or the strict cue count, the cues and the warnings.
     */
    public static function damagedFiles(): array
    {
        return [
            "SubRip without cue numbers" => [
                "missing_cue_numbers.srt",
                SubRipParser::class,
                "Block #1 doesn't seem to have a cue-number on its first line!",
                [
                    [1, 3.5, "The train leaves at nine."],
                    [4, 6, "Platform two, next to the bakery."],
                    [6.5, 8, "Bring an umbrella."],
                    [9, 11, "It may rain in the afternoon."],
                ],
                [
                    [5, 1, self::REPAIRED, "Block #1 has no cue number on line 5. The parser read the cue without it."],
                    [8, 2, self::REPAIRED, "Block #2 has no cue number on line 8. The parser read the cue without it."],
                ],
            ],
            "SubRip with a bad timestamp and a broken arrow" => [
                "bad_timestamp.srt",
                SubRipParser::class,
                "The timeString-string of at least one cue could not be parsed: 00:00:0G,000",
                [
                    [1, 3, "Good morning."],
                    [10, 12, "See you tomorrow."],
                ],
                [
                    [5, 1, self::SKIPPED, "The timeString-string of at least one cue could not be parsed: 00:00:0G,000"],
                    [9, 2, self::SKIPPED, "Block #2 doesn't seem to have its timestamps on its second line!"],
                ],
            ],
            "SubRip without empty lines between cues" => [
                "missing_empty_line.srt",
                SubRipParser::class,
                2,
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                    [8, 10, "Stay at home."],
                ],
                [
                    [4, 0, self::REPAIRED, "Block #0 has no empty line before line 4. The parser split the block there."],
                    [7, 0, self::REPAIRED, "Block #0 has no empty line before line 7. The parser split the block there."],
                    [7, 0, self::REPAIRED, "Block #0 has no cue number on line 7. The parser read the cue without it."],
                ],
            ],
            "SubRip with text before the first cue" => [
                "text_before_first_cue.srt",
                SubRipParser::class,
                "Block #0 doesn't seem to have a cue-number on its first line!",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [1, 0, self::SKIPPED, "Block #0 doesn't seem to have a cue-number on its first line!"],
                ],
            ],
            "SubRip with a truncated last cue" => [
                "truncated_last_cue.srt",
                SubRipParser::class,
                "Block #2 doesn't have any text lines!",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [9, 2, self::SKIPPED, "Block #2 doesn't have any text lines!"],
                ],
            ],
            "SubRip with mixed line endings" => [
                "mixed_line_endings.srt",
                SubRipParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "WebVTT with a bad timestamp" => [
                "bad_timestamp.vtt",
                WebVttParser::class,
                "The time-string of at least one cue could not be parsed: 00:00:06,000",
                [
                    [1, 3, "Good morning."],
                    [7, 9, "See you tomorrow."],
                ],
                [
                    [7, 2, self::SKIPPED, "The time-string of at least one cue could not be parsed: 00:00:06,000"],
                ],
            ],
            "WebVTT without empty lines after the header and between cues" => [
                "missing_empty_line.vtt",
                WebVttParser::class,
                "No empty line found after the first line containing WEBVTT!",
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                    [8, 10, "Stay at home."],
                ],
                [
                    [4, 0, self::REPAIRED, "No empty line found after the first line containing WEBVTT! " .
                                           "The parser split the header block at line 4."],
                ],
            ],
            "WebVTT with text before the first cue" => [
                "text_before_first_cue.vtt",
                WebVttParser::class,
                "Block #1 doesn't match anything that we can parse as a WebVTT cue!",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [3, 1, self::SKIPPED, "Block #1 doesn't match anything that we can parse as a WebVTT cue!"],
                ],
            ],
            "WebVTT with a truncated last cue" => [
                "truncated_last_cue.vtt",
                WebVttParser::class,
                "Block #3 doesn't have any text lines!",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [9, 3, self::SKIPPED, "Block #3 doesn't have any text lines!"],
                ],
            ],
            "WebVTT with mixed line endings" => [
                "mixed_line_endings.vtt",
                WebVttParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "SBV with a bad timestamp" => [
                "bad_timestamp.sbv",
                SbvParser::class,
                "The timeString-string of at least one cue could not be parsed: 0:00:06.00",
                [
                    [1, 3, "Good morning."],
                    [7, 9, "See you tomorrow."],
                ],
                [
                    [4, 1, self::SKIPPED, "The timeString-string of at least one cue could not be parsed: 0:00:06.00"],
                ],
            ],
            "SBV without an empty line between cues" => [
                "missing_empty_line.sbv",
                SbvParser::class,
                2,
                [
                    [1, 2.5, "The wind is cold today."],
                    [3, 5, "Snow falls in the hills."],
                    [5.5, 7, "The roads are closed."],
                ],
                [
                    [3, 0, self::REPAIRED, "Block #0 has no empty line before line 3. The parser split the block there."],
                ],
            ],
            "SBV with text before the first cue" => [
                "text_before_first_cue.sbv",
                SbvParser::class,
                "Block #0 doesn't have any text lines!",
                [
                    [1, 3, "Clouds move in from the west."],
                    [4, 6, "Sun again by Friday."],
                ],
                [
                    [1, 0, self::SKIPPED, "Block #0 doesn't have any text lines!"],
                ],
            ],
            "SBV with a truncated last cue" => [
                "truncated_last_cue.sbv",
                SbvParser::class,
                "Block #2 doesn't have any text lines!",
                [
                    [1, 3, "The next stop is the station."],
                    [4, 6, "Please mind the gap."],
                ],
                [
                    [7, 2, self::SKIPPED, "Block #2 doesn't have any text lines!"],
                ],
            ],
            "SBV with mixed line endings" => [
                "mixed_line_endings.sbv",
                SbvParser::class,
                3,
                [
                    [1, 3, "The oven is hot."],
                    [4, 6, "The loaves rise."],
                    [7, 9, "The shop is open."],
                ],
                [],
            ],
            "MicroDVD with a release name and a line without frames" => [
                "release_name.sub",
                MicroDvdParser::class,
                "The frame rate is unknown. Pass it to the constructor or start the file with {1}{1}<fps>.",
                [
                    [1, 3, "The ferry leaves at noon."],
                    [5, 7, "<i>Tickets are sold on board.</i>"],
                ],
                [
                    [1, 0, self::SKIPPED, "Line 1 is not a MicroDVD cue: Movie.Name.2003.DVDRip (line 1)"],
                    [4, 3, self::SKIPPED, "Line 4 is not a MicroDVD cue: {x}{120}The deck is wet. (line 4)"],
                ],
            ],
            "ASS without a Format line, with a short event and a bad time" => [
                "broken_events.ass",
                AssParser::class,
                "Line 11 has fewer fields than the Format line of the [Events] section: Dialogue: 0,0:00:04.00,0:00:06.00,Default",
                [
                    [1, 3, "The boats come in at dawn."],
                    [10, 12, "<i>Fish is sold at the pier.</i>"],
                ],
                [
                    [11, 1, self::SKIPPED, "Line 11 has fewer fields than the Format line of the [Events] section: " .
                                           "Dialogue: 0,0:00:04.00,0:00:06.00,Default (line 11)"],
                    [12, 2, self::SKIPPED, "The time of at least one event could not be parsed: 0:00:0x.00"],
                ],
            ],
            "SubViewer with text before the header and a bad time line" => [
                "bad_time_line.sub",
                SubViewerParser::class,
                "Line 1 is neither a header tag nor a timing line: Downloaded from a subtitle site",
                [
                    [1, 3, "The market opens at eight."],
                    [7, 9, "The stalls close\nat noon."],
                ],
                [
                    [1, 0, self::SKIPPED, "Line 1 is neither a header tag nor a timing line: Downloaded from a subtitle site (line 1)"],
                    [9, 1, self::SKIPPED, "Line 9 is a timing line with a bad time: 00:00:04.00,00:00:0x.00"],
                ],
            ],
            "MPSub without a FORMAT line, with a bad timing line and a truncated last cue" => [
                "bad_timing_line.mpsub",
                MpSubParser::class,
                "Line 7 is neither a header, a comment nor a timing line: 1 x",
                [
                    [1, 3, "The bus leaves at ten."],
                    [5, 7, "Seats are free."],
                ],
                [
                    [4, 0, self::REPAIRED, "The file has no FORMAT line before line 4. The parser read the times as seconds."],
                    [7, 1, self::SKIPPED, "Line 7 is neither a header, a comment nor a timing line: 1 x (line 7)"],
                    [13, 3, self::SKIPPED, "The cue that ends on line 13 doesn't have any text lines! (line 13)"],
                ],
            ],
            "LRC with a broken time tag" => [
                "broken_time_tag.lrc",
                LyricsParser::class,
                3,
                [
                    [1, 4.5, "The sun comes up"],
                    [4.5, 9, "Birds sing in the trees"],
                    [9, 12, "We walk to the lake"],
                ],
                [
                    [6, 4, self::SKIPPED, "Line 6 has a time tag that could not be parsed: [01:2x.00]The path is long"],
                ],
            ],
            "SAMI with a SYNC tag without a Start time" => [
                "bad_sync_start.smi",
                SamiParser::class,
                "SYNC tag 3 has no valid Start attribute.",
                [
                    [1, 3, "Water the roses."],
                    [6, 8, "Pick the <i>beans</i>."],
                ],
                [
                    [14, 2, self::SKIPPED, "SYNC tag 3 has no valid Start attribute."],
                ],
            ],
        ];
    }


    public static function streamedFiles(): array
    {
        $readers = [SubRipParser::class => SubRipStreamReader::class, WebVttParser::class => WebVttStreamReader::class];

        $cases = [];
        foreach (self::damagedFiles() as $name => [$file, $parserClass]) {
            if (isset($readers[$parserClass])) {
                $cases[$name] = [$file, $parserClass, $readers[$parserClass]];
            }
        }

        return $cases;
    }


    #[DataProvider("damagedFiles")]
    public function testLenientModeReturnsTheIntactCuesAndOneWarningPerProblem(
        string $file,
        string $parserClass,
        string|int $strictResult,
        array $expectedCues,
        array $expectedWarnings
    ): void {
        $parser   = (new $parserClass())->setLenient();
        $subtitle = $parser->parse(file_get_contents(self::DIR . $file));

        $this->assertEquals($expectedCues, $this->cueRows($subtitle->getCues()));
        $this->assertSame($expectedWarnings, $this->warningRows($parser->getWarnings()));
    }


    #[DataProvider("damagedFiles")]
    public function testStrictModeKeepsItsResult(
        string $file,
        string $parserClass,
        string|int $strictResult
    ): void {
        $content = file_get_contents(self::DIR . $file);
        if (is_int($strictResult)) {
            $parser = new $parserClass();
            $this->assertCount($strictResult, $parser->parse($content)->getCues());
            $this->assertSame([], $parser->getWarnings());

            return;
        }

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage($strictResult);
        (new $parserClass())->parse($content);
    }


    #[DataProvider("streamedFiles")]
    public function testStreamReaderReturnsTheCuesAndWarningsOfTheParser(string $file, string $parserClass, string $readerClass): void
    {
        $parser   = (new $parserClass())->setLenient();
        $subtitle = $parser->parse(file_get_contents(self::DIR . $file));

        $reader = (new $readerClass())->setLenient();
        $cues   = iterator_to_array($reader->read(self::DIR . $file));

        $this->assertSame(array_keys($cues), range(0, count($cues) - 1));
        $this->assertSame($this->cueRows($subtitle->getCues()), $this->cueRows($cues));
        $this->assertEquals($parser->getWarnings(), $reader->getWarnings());
    }


    public function testTheExampleOfTheIssueKeepsCuesOneAndThree(): void
    {
        $content = "1\n00:00:01,000 --> 00:00:04,000\nHello\n\n" .
                   "2\n00:00:05,000 -> 00:00:07,000\nBroken arrow\n\n" .
                   "3\n00:00:08,000 --> 00:00:10,000\nStill fine\n";

        $parser   = (new SubRipParser())->setLenient();
        $subtitle = Subtitle::parse($content, $parser);

        $this->assertEquals([[1, 4, "Hello"], [8, 10, "Still fine"]], $this->cueRows($subtitle->getCues()));
        $this->assertEquals(
            [new ParseWarning(
                "Block #1 doesn't seem to have its timestamps on its second line!",
                5,
                1,
                ["2", "00:00:05,000 -> 00:00:07,000", "Broken arrow"],
                ParseWarning::SKIPPED
            )],
            $parser->getWarnings()
        );
    }


    public function testSubtitleParseConvertsTheEncodingBeforeTheParserInstance(): void
    {
        $content = mb_convert_encoding("1\n00:00:01,000 --> 00:00:02,000\nCafé au lait\n\nbroken\n", "Windows-1252", "UTF-8");
        $parser  = (new SubRipParser())->setLenient();

        $subtitle = Subtitle::parse($content, $parser, "Windows-1252");

        $this->assertSame("Café au lait", $subtitle->getCues()[0]->getText());
        $this->assertCount(1, $parser->getWarnings());
    }


    public function testSubtitleParseWithAClassNameStaysStrict(): void
    {
        $this->expectException(ParsingException::class);
        Subtitle::parse(file_get_contents(self::DIR . "bad_timestamp.srt"), SubRipParser::class);
    }


    public function testEachParseCallStartsWithoutWarnings(): void
    {
        $parser = (new SubRipParser())->setLenient();
        $parser->parse(file_get_contents(self::DIR . "bad_timestamp.srt"));
        $parser->parse(file_get_contents(self::DIR . "mixed_line_endings.srt"));

        $this->assertSame([], $parser->getWarnings());
    }


    public function testSetLenientFalseRestoresStrictMode(): void
    {
        $parser = (new SbvParser())->setLenient()->setLenient(false);

        $this->assertFalse($parser->isLenient());
        $this->expectException(ParsingException::class);
        $parser->parse(file_get_contents(self::DIR . "bad_timestamp.sbv"));
    }


    public function testAnEmptyFileGivesNoCuesAndOneWarning(): void
    {
        foreach ([new SubRipParser(), new SbvParser()] as $parser) {
            $subtitle = $parser->setLenient()->parse(" \n\n");

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([[1, 0, self::SKIPPED, "The file has no cues."]], $this->warningRows($parser->getWarnings()));
        }
    }


    public function testWebVttWithoutTheSignatureStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file doesn't start with the string WEBVTT!");
        (new WebVttParser())->setLenient()->parse("00:00:01.000 --> 00:00:02.000\ntext\n");
    }


    public function testWebVttStreamReaderWithoutTheSignatureStillThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The file doesn't start with the string WEBVTT!");
        iterator_to_array((new WebVttStreamReader())->setLenient()->read(self::DIR . "bad_timestamp.srt"));
    }


    public function testWebVttLineNumbersCountTheEmptyLinesBeforeTheSignature(): void
    {
        $parser = (new WebVttParser())->setLenient();
        $parser->parse("\n\nWEBVTT\n\nbroken\n\n00:00:01.000 --> 00:00:02.000\ntext\n");

        $this->assertSame(5, $parser->getWarnings()[0]->lineNumber);
    }


    public function testWebVttKeepsTheHeaderLinesBeforeTheSplit(): void
    {
        $subtitle = (new WebVttParser())->setLenient()->parse(file_get_contents(self::DIR . "missing_empty_line.vtt"));

        $this->assertSame(["headerLines" => ["Kind: captions", "Language: en"]], $subtitle->getFormatData("vtt"));
    }


    public function testSubViewerKeepsTheTextOfTheSkippedCueInTheWarning(): void
    {
        $parser = (new SubViewerParser())->setLenient();
        $parser->parse(file_get_contents(self::DIR . "bad_time_line.sub"));

        $this->assertSame(["00:00:04.00,00:00:0x.00", "Apples are cheap today."], $parser->getWarnings()[1]->block);
    }


    public function testSubViewerStrictModeReadsABadTimeLineAsText(): void
    {
        $subtitle = (new SubViewerParser())->parse("00:00:01.00,00:00:03.00\nOne\n\n00:00:04.00,00:00:0x.00\nTwo\n");

        $this->assertSame(["One", "00:00:04.00,00:00:0x.00", "Two"], $subtitle->getCues()[0]->getLines());
    }


    public function testSubViewer1SkipsABrokenHeaderLine(): void
    {
        $parser   = (new SubViewerParser())->setLenient();
        $subtitle = $parser->parse("[TITLE]\nMarket\nbroken\n" . SubViewerParser::START_SCRIPT . "\n[00:00:01]\nHello\n[00:00:02]\n");

        $this->assertSame("Hello", $subtitle->getCues()[0]->getText());
        $this->assertSame([[3, 0, self::SKIPPED, "Line 3 is not a SubViewer 1 header tag: broken (line 3)"]], $this->warningRows($parser->getWarnings()));
    }


    public function testMpSubSkipsABadFormatLineAndReadsTheTimesAsSeconds(): void
    {
        $parser   = (new MpSubParser())->setLenient();
        $subtitle = $parser->parse("FORMAT=PAL\n\n1 2\nHello\n");

        $this->assertEquals([[1, 3, "Hello"]], $this->cueRows($subtitle->getCues()));
        $this->assertSame([[1, 0, self::SKIPPED, "Line 1 has an unknown FORMAT value: PAL (line 1)"]], $this->warningRows($parser->getWarnings()));
    }


    public function testSamiWarningHoldsTheLinesOfTheSkippedSync(): void
    {
        $parser = (new SamiParser())->setLenient();
        $parser->parse(file_get_contents(self::DIR . "bad_sync_start.smi"));

        $this->assertSame(["<SYNC Start=><P Class=ENCC>Cut the grass."], $parser->getWarnings()[0]->block);
    }


    public function testParsersWithoutLenientModeStillThrow(): void
    {
        $this->expectException(ParsingException::class);
        (new SccParser())->setLenient()->parse("Scenarist_SCC V1.0\n\nbroken\n");
    }


    /**
     * @param iterable<SubtitleCue> $cues
     */
    private function cueRows(iterable $cues): array
    {
        $rows = [];
        foreach ($cues as $cue) {
            $rows[] = [$cue->getStart(), $cue->getEnd(), $cue->getText()];
        }

        return $rows;
    }


    /**
     * @param list<ParseWarning> $warnings
     */
    private function warningRows(array $warnings): array
    {
        return array_map(
            fn (ParseWarning $warning): array => [$warning->lineNumber, $warning->blockIndex, $warning->action, $warning->message],
            $warnings
        );
    }
}
