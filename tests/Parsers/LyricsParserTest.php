<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Subtitle;

class LyricsParserTest extends TestCase
{
    public function testValidLrcFileParses()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"), LyricsParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/valid.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }


    public function testKeepsIdTags()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), LyricsParser::class
        );

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags_formatted.lrc"),
            $subtitle->format(LyricsFormatter::class)
        );
    }


    public function testExceededMinutesIgnoredCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_minutes.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Second Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededSecondsIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_seconds.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testExceededMilliSecondAccuracyIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/exceeded_centi_accuracy.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTextIgnoresCue()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), LyricsParser::class);

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testMissingTimestampIgnoresCue()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/missing_timestamps.lrc"), LyricsParser::class
        );

        $this->assertSame("First Text", $subtitle->getCues()[0]->getLines()[0]);
        $this->assertSame("Third Text", $subtitle->getCues()[1]->getLines()[0]);
        $this->assertSame(2, count($subtitle->getCues()));
    }


    public function testEndTimeIsNextCueStartIncludingCentiseconds(): void
    {
        $subtitle = Subtitle::parse("[00:01.00] First\n[00:02.75] Second\n", LyricsParser::class);

        $this->assertSame(2.75, $subtitle->getCues()[0]->getEnd());
    }


    public function testIdTagsBecomeMetadataOrLrcFormatData(): void
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/with_id_tags.lrc"), LyricsParser::class
        );

        $this->assertSame(
            [
                Subtitle::METADATA_AUTHOR => "Creator of the Songtext",
                Subtitle::METADATA_TITLE  => "Lyrics (song) title",
                Subtitle::METADATA_ALBUM  => "Album where the song is from",
            ],
            $subtitle->getAllMetadata()
        );
        $this->assertSame(
            ["idTags" => ["by" => "Creator of the LRC file", "length" => "How long the song is"]],
            $subtitle->getFormatData(LyricsParser::FORMAT)
        );
    }


    public function testIdTagKeysAreCaseInsensitiveAndValuesAreTrimmed(): void
    {
        $subtitle = Subtitle::parse("[TI: Morning Train ]
[Ar:Station Choir]
[00:01.00] Text
", LyricsParser::class);

        $this->assertSame("Morning Train", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("Station Choir", $subtitle->getMetadata(Subtitle::METADATA_ARTIST));
    }


    public function testCommentTagsBecomeComments(): void
    {
        $subtitle = Subtitle::parse(
            "[#:Header note]
[00:01.00] First
[#:Before second]
[00:02.00] Second
", LyricsParser::class
        );

        $this->assertSame(
            [
                ["text" => "Header note", "beforeCueIndex" => 0],
                ["text" => "Before second", "beforeCueIndex" => 1],
            ],
            $subtitle->getComments()
        );
    }


    public function testOffsetMakesLyricsShowEarlier(): void
    {
        $subtitle = Subtitle::parse(
            "[00:12.00] First
[offset:+500]
[00:17.20] Second <00:18.00>word
", LyricsParser::class
        );

        $this->assertSame(11.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame(16.7, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(16.7, $subtitle->getCues()[1]->getStart());
        $this->assertSame("Second <00:00:17.500>word", $subtitle->getCues()[1]->getText());
        $this->assertSame([], $subtitle->getFormatData(LyricsParser::FORMAT));
    }


    public function testNegativeOffsetMakesLyricsShowLater(): void
    {
        $subtitle = Subtitle::parse("[offset:-250]
[00:12.00] First
", LyricsParser::class);

        $this->assertSame(12.25, $subtitle->getCues()[0]->getStart());
    }


    public function testOffsetDoesNotMakeTimesNegative(): void
    {
        $subtitle = Subtitle::parse("[offset:1000]
[00:00.50] First
", LyricsParser::class);

        $this->assertSame(0.0, $subtitle->getCues()[0]->getStart());
    }


    public function testInvalidOffsetIsKeptAsFormatData(): void
    {
        $subtitle = Subtitle::parse("[offset:soon]
[00:12.00] First
", LyricsParser::class);

        $this->assertSame(12.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(["idTags" => ["offset" => "soon"]], $subtitle->getFormatData(LyricsParser::FORMAT));
    }


    public function testLineWithSeveralTimestampsBecomesOneCuePerTimestamp(): void
    {
        $subtitle = Subtitle::parse(
            "[00:12.00][01:15.30]Chorus
[00:17.20]Verse
", LyricsParser::class
        );
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([12.0, 17.2, "Chorus"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([17.2, 75.3, "Verse"], [$cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getText()]);
        $this->assertSame([75.3, 85.3, "Chorus"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
    }


    public function testTimestampWithoutTextEndsThePreviousCue(): void
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/missing_text.lrc"), LyricsParser::class
        );

        $this->assertSame(154.56, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(225.67, $subtitle->getCues()[1]->getStart());
    }


    public function testTimestampWithoutTextEndsTheLastCue(): void
    {
        $subtitle = Subtitle::parse("[00:01.00] First
[00:03.50]
", LyricsParser::class);

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame(3.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testAcceptsTimestampsWithoutFractionAndWithMilliseconds(): void
    {
        $subtitle = Subtitle::parse("[00:01] First
[00:02.345] Second
", LyricsParser::class);

        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(2.345, $subtitle->getCues()[1]->getStart());
    }


    public function testTimestampNeedsADotBeforeTheFraction(): void
    {
        $subtitle = Subtitle::parse("[00:12x00] Wrong
[00:13.00] Right
", LyricsParser::class);

        $this->assertCount(1, $subtitle->getCues());
        $this->assertSame("Right", $subtitle->getCues()[0]->getText());
    }


    public function testLastCueLastsTenSecondsByDefault(): void
    {
        $subtitle = Subtitle::parse("[00:01.00] First
", LyricsParser::class);

        $this->assertSame(11.0, $subtitle->getCues()[0]->getEnd());
    }


    public function testLastCueDurationIsAnOption(): void
    {
        $subtitle = (new LyricsParser(2.5))->parse("[00:01.00] First
");

        $this->assertSame(3.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testNegativeLastCueDurationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LyricsParser(-1);
    }


    public function testWordTimestampsBecomeCoreMarkup(): void
    {
        $subtitle = Subtitle::parse("[00:21.10]<00:21.10>Bread <00:21.60>is <61:01.905>warm
", LyricsParser::class);

        $this->assertSame(
            "<00:00:21.100>Bread <00:00:21.600>is <01:01:01.905>warm",
            $subtitle->getCues()[0]->getText()
        );
    }


    /**
     * @return array<string, array{string, int, array{float, float, string}, array{float, float, string}}>
     */
    public static function realFiles(): array
    {
        return [
            "justan-1"             => [
                "justan-1.lrc", 42, [0.0, 1.0, "火车七点出发"], [202.98, 212.98, "烤箱闻起来很香"],
            ],
            "justan-4"             => [
                "justan-4.lrc", 34, [0.0, 4.0, "火车七点出发"], [202.0, 207.0, "天气准时到站　站台下了一整天"],
            ],
            "lrc-maker-nami"       => [
                "lrc-maker-nami.lrc", 39, [0.0, 1.0, "電車は七時に出る：example"], [235.536, 243.353, "——天気は晴れです、駅は少し混む。"],
            ],
            "mantas-done-lrc"      => [
                "mantas-done-lrc.lrc", 5, [8.62, 9.64, "Trains run early"], [22.63, 32.63, "Rain comes later"],
            ],
            "subsrt-sample"        => [
                "subsrt-sample.lrc", 6, [12.0, 17.2, "Line 1 about the train"], [29.02, 39.02, "Line 6 about the bread"],
            ],
            "handwritten-core"     => [
                "handwritten-core.lrc",
                5,
                [5.0, 9.4, "The train leaves at seven"],
                [32.8, 42.8, "Ring the bell, ring the bell"],
            ],
            "handwritten-enhanced" => [
                "handwritten-enhanced.lrc",
                3,
                [3.0, 5.9, "<00:00:03.000> Slow <00:00:03.550> river <00:00:04.400> runs"],
                [9.6, 19.6, "<00:00:09.600> Into <00:00:10.100> the <00:00:10.650> sea"],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, int $cueCount, array $firstCue, array $lastCue): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/real/" . $file), LyricsParser::class);
        $cues     = $subtitle->getCues();
        $last     = $cues[count($cues) - 1];

        $this->assertCount($cueCount, $cues);
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesRoundTrip(string $file): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/lrc/real/" . $file), LyricsParser::class);
        $again    = Subtitle::parse(
            $subtitle->format(LyricsFormatter::class), LyricsParser::class
        );

        $this->assertSame($this->describe($subtitle), $this->describe($again));
    }


    public function testRealFileMetadata(): void
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/lrc/real/subsrt-sample.lrc"), LyricsParser::class
        );

        $this->assertSame("Weather (morning) report", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("Station choir", $subtitle->getMetadata(Subtitle::METADATA_ARTIST));
        $this->assertSame("Songs from the bakery", $subtitle->getMetadata(Subtitle::METADATA_ALBUM));
        $this->assertSame("Writer of the words", $subtitle->getMetadata(Subtitle::METADATA_AUTHOR));
        $this->assertSame(
            ["length", "by", "offset", "re", "ve"],
            array_keys($subtitle->getFormatData(LyricsParser::FORMAT)["idTags"])
        );
    }


    private function describe(Subtitle $subtitle): array
    {
        $metadata = $subtitle->getAllMetadata();
        ksort($metadata);

        // the formatter writes centiseconds, so millisecond timestamps come back rounded
        return [
            array_map(
                fn ($cue) => [round($cue->getStart(), 2), round($cue->getEnd(), 2), $cue->getLines()],
                $subtitle->getCues()
            ),
            $metadata,
            $subtitle->getFormatData(LyricsParser::FORMAT),
            $subtitle->getComments(),
        ];
    }
}
