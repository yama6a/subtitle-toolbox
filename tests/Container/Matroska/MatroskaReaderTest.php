<?php

namespace SubtitleToolbox\Container\Matroska;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\PgsFixtures;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../../files/mkv/generator/MkvFixtures.php";

class MatroskaReaderTest extends TestCase
{
    private const DIR = __DIR__ . "/../../files/mkv/";


    public static function fixtures(): array
    {
        return array_map(fn (string $method): array => [$method], MkvFixtures::FILES);
    }


    #[DataProvider("fixtures")]
    public function testFixturesMatchTheGenerator(string $method): void
    {
        $this->assertSame(file_get_contents(self::DIR . array_search($method, MkvFixtures::FILES, true)), MkvFixtures::$method());
    }


    public function testListsOnlyTheSubtitleTracks(): void
    {
        $tracks = MatroskaReader::open(self::DIR . "text_tracks.mkv")->getSubtitleTracks();

        $this->assertSame(
            [
                [3, "S_TEXT/UTF8", "de", "Deutsch (Forced)", false, true],
                [4, "S_TEXT/ASS", "eng", "English", true, false],
                [5, "S_TEXT/WEBVTT", "fre", "Français", false, false],
                [6, "S_TEXT/SSA", "spa", null, false, false],
                [7, "S_VOBSUB", "ita", null, false, false],
                [8, "S_TEXT/UTF8", "eng", null, false, false],
            ],
            array_map(fn (MatroskaTrack $t): array => [$t->number, $t->codecId, $t->language, $t->name, $t->default, $t->forced], $tracks),
        );
    }


    public function testExtractsSubRipWithLanguageAndForcedFlag(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(3);

        $this->assertSame(
            [
                [1.0, 3.5, ["Der Zug nach Hamburg fährt um acht Uhr ab."], true],
                [4.0, 6.0, ["<i>Gleis 4</i>", "Bitte nicht einsteigen."], true],
                [9.8, 12.0, ["Die Bäckerei öffnet um sechs."], true],
                [15.0, 17.25, ["Morgen wird es sonnig", "und warm."], true],
            ],
            $this->cues($subtitle),
        );
        $this->assertSame("de", $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testExtractsAssInReadOrderWithTheHeaderFromCodecPrivate(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(4);

        $this->assertSame(
            MkvFixtures::ASS_HEADER .
            "Dialogue: 1,0:00:01.00,0:00:06.00,Sign,,0,0,0,,{\\an8}PLATFORM 4\n" .
            "Dialogue: 0,0:00:01.00,0:00:04.00,Default,Guard,0,0,0,,The train to the coast leaves at eight.\n" .
            "Dialogue: 0,0:00:07.00,0:00:09.00,Default,,0,0,0,,The bakery on the corner\\Nis open {\\i1}every{\\i0} day.\n" .
            "Dialogue: 0,0:00:11.00,0:00:13.50,Default,Guard,0,0,0,,Bring an umbrella, it may rain later.\n",
            $subtitle->toString(Format::Ass, new WriteOptions(bom: false)),
        );
        $this->assertSame(8, $subtitle->getCues()[0]->getAlignment());
        $this->assertFalse($subtitle->getCues()[0]->isForced());
    }


    public function testExtractsSsaAndAddsTheEventsSectionThatCodecPrivateLacks(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(6);

        $this->assertSame(
            [
                [3.0, 5.0, ["El tren sale a las ocho."], false],
                [13.0, 15.5, ["La panadería abre a las seis."], false],
            ],
            $this->cues($subtitle),
        );
        $this->assertStringContainsString(
            "Dialogue: Marked=0,0:00:03.00,0:00:05.00,Default,,0000,0000,0000,,El tren sale a las ocho.",
            $subtitle->toString(Format::Ass),
        );
    }


    public function testExtractsWebVttWithSettingsIdentifiersCommentsAndRelativeTimestamps(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(5);

        $this->assertSame(
            "WEBVTT - Gare\n\nSTYLE\n::cue {\n  color: yellow;\n}\n\nNOTE Fichier écrit pour les tests\n\n" .
            "annonce-1\n00:00:02.000 --> 00:00:05.000 line:10% align:start\nLe train pour Lyon part à huit heures.\n\n" .
            "NOTE Deuxième annonce\n\n" .
            "2\n00:00:06.000 --> 00:00:08.500\nLa boulangerie ouvre à six heures.\nLe pain est <00:00:07.500>encore chaud.\n\n" .
            "3\n00:00:12.000 --> 00:00:14.000\nDemain, il fera beau.\n",
            $subtitle->toString(Format::WebVtt, new WriteOptions(bom: false)),
        );
    }


    public function testBlocksWithoutDurationEndAtTheNextBlockAndTheLastAfterFiveSeconds(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(8);

        $this->assertSame(
            [
                [2.5, 8.0, ["Next stop: Central Station."], false],
                [8.0, 16.0, ["Doors open on the left."], false],
                [16.0, 21.0, ["End of the line."], false],
            ],
            $this->cues($subtitle),
        );
        $this->assertSame("eng", $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public static function compressedTracks(): array
    {
        return [
            "zlib"                       => [3, [[1.0, 3.0, ["The ferry leaves at noon."]], [4.0, 6.0, ["Tickets are sold", "at the harbour."]]]],
            "zlib with CodecPrivate"     => [4, [[2.0, 5.0, ["The museum is closed on Mondays."]]]],
            "header stripping"           => [5, [[1.5, 3.5, ["Weather: sunny."]], [5.0, 7.5, ["Weather: cloudy, with light rain."]]]],
            "zlib, then header stripping" => [6, [[3.0, 4.5, ["Bakery: fresh bread at seven."]]]],
        ];
    }


    #[DataProvider("compressedTracks")]
    public function testDecodesContentEncodings(int $track, array $expected): void
    {
        $cues = MatroskaReader::open(self::DIR . "compressed.mkv")->extract($track)->getCues();

        $this->assertSame($expected, array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues));
    }


    public function testReadsUnknownSizesAndTheTimestampScale(): void
    {
        $subtitle = MatroskaReader::open(self::DIR . "unknown_sizes.mkv")->extract(2);

        $this->assertSame(
            [
                [1.235, 3.0, ["Tåget till Malmö är försenat."], false],
                [4.0, 5.5, ["Bageriet stänger klockan fem."], false],
                [7.0, 9.0, ["I morgon blir det soligt."], false],
            ],
            $this->cues($subtitle),
        );
    }


    public function testFindsTracksAfterTheClustersThroughTheSeekHead(): void
    {
        $mkv = MatroskaReader::open(self::DIR . "seek_head.mkv");

        $this->assertSame("S_TEXT/WEBVTT", $mkv->getSubtitleTracks()[0]->codecId);
        $this->assertSame([[1.0, 3.0, ["The library opens at nine."], false]], $this->cues($mkv->extract(2)));
    }


    public function testExtractsPgsAsThePgsParserReadsTheSupFile(): void
    {
        $mkv      = MatroskaReader::open(self::DIR . "pgs.mkv");
        $expected = (new PgsParser())->parse(PgsFixtures::shapes1080p())->getCues();
        $toArray  = fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getAllFormatData(), $cue->isForced()];

        $this->assertSame(array_map($toArray, $expected), array_map($toArray, $mkv->extract(3)->getCues()));

        $forced = $mkv->extract(4)->getCues();
        $this->assertSame(
            array_map(fn (array $cue): array => [$cue[0], $cue[1], $cue[2]], array_map($toArray, $expected)),
            array_map(fn (array $cue): array => [$cue[0], $cue[1], $cue[2]], array_map($toArray, $forced)),
        );
        $this->assertSame(array_fill(0, count($forced), true), array_map(fn (SubtitleCue $cue): bool => $cue->isForced(), $forced));
    }


    public function testLeavesAStreamOpen(): void
    {
        $stream = fopen(self::DIR . "seek_head.mkv", "rb");
        $mkv    = MatroskaReader::open($stream);
        $mkv->extract(2);
        unset($mkv);

        $this->assertIsResource($stream);
        fclose($stream);
    }


    public function testThrowsForAnUnsupportedCodec(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Track 7 has the codec S_VOBSUB.");

        MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(7);
    }


    public function testThrowsForAVideoTrack(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MatroskaReader::open(self::DIR . "text_tracks.mkv")->extract(1);
    }


    public function testThrowsForBzlibCompression(): void
    {
        $this->expectException(ParsingException::class);

        MatroskaReader::open(self::DIR . "compressed.mkv")->extract(7);
    }


    public function testThrowsForAFileThatIsNotMatroska(): void
    {
        $this->expectException(ParsingException::class);

        MatroskaReader::open(__DIR__ . "/../../files/pgs/shapes_576p.sup");
    }


    public function testPeakMemoryStaysBelowFourMegabytesForLargeVideoBlocks(): void
    {
        $stream = tmpfile();
        $video  = str_repeat("\x55", 1 << 20);
        fwrite($stream, MkvFixtureWriter::ebmlHeader());
        fwrite($stream, MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info() . MkvFixtureWriter::element(
            MkvFixtureWriter::TRACKS,
            MkvFixtureWriter::trackEntry(["number" => 1, "type" => MkvFixtureWriter::TRACK_VIDEO, "codecId" => "V_UNCOMPRESSED"]) .
            MkvFixtureWriter::trackEntry(["number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8"]),
        )));
        for ($second = 0; $second < 64; $second++) {
            fwrite($stream, MkvFixtureWriter::cluster($second * 1000, [
                MkvFixtureWriter::simpleBlock(1, 0, $video),
                MkvFixtureWriter::blockGroup(2, 100, "The bus leaves in $second minutes.", 800),
            ]));
        }
        unset($video);
        rewind($stream);

        memory_reset_peak_usage();
        $before   = memory_get_usage();
        $subtitle = MatroskaReader::open($stream)->extract(2);
        $peak     = memory_get_peak_usage() - $before;
        fclose($stream);

        $this->assertCount(64, $subtitle->getCues());
        $this->assertLessThan(4 << 20, $peak);
    }


    /**
     * @return list<array{float, float, list<string>, bool}>
     */
    private function cues(Subtitle $subtitle): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->isForced()],
            $subtitle->getCues(),
        );
    }
}
