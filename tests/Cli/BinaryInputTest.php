<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Matroska\MkvFixtureWriter;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\BinaryTestCase;

require_once __DIR__ . "/../files/mkv/generator/MkvFixtureWriter.php";

/**
 * Checks how the commands read: directories, standard input, input formats, frame rates and MKV tracks.
 */
class BinaryInputTest extends BinaryTestCase
{
    public function testConvertReadsTheSubtitleFilesOfADirectory(): void
    {
        mkdir("$this->dir/in");
        foreach (["trip.srt", "shop.vtt", "notes.txt"] as $name) {
            copy(self::FIXTURES . $name, "$this->dir/in/$name");
        }

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "in", "--to", "srt", "--output-dir", "out"]);

        $this->assertSame([0, "in/shop.vtt -> out/shop.srt\nin/trip.srt -> out/trip.srt\n2 files: 2 succeeded, 0 failed.\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($this->tripAs(Format::SubRip), $this->file("out/trip.srt"));
    }


    public function testDashReadsStandardInputAndWritesStandardOutput(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "-", "--to", "vtt"], file_get_contents(self::FIXTURES . "trip.srt"));

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], [$code, $stdout, $stderr]);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "trip.srt", "-o", "-", "--to", "vtt"]));
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "-", "--from", "srt", "--to", "vtt"], $this->file("trip.srt")));
    }


    public function testTheContentSetsTheInputFormatBeforeTheExtension(): void
    {
        rename("$this->dir/trip.srt", "$this->dir/trip.txt");

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runBinary(["convert", "trip.txt", "--to", "vtt", "-o", "-"]));
        $this->assertSame(
            [3, "", "notes.txt: UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                    "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n"],
            $this->runBinary(["convert", "notes.txt", "--to", "vtt"])
        );
        $this->assertSame([3, "", "missing.srt: The file does not exist.\n"], $this->runBinary(["convert", "missing.srt", "--to", "vtt"]));
    }


    public function testCloudSpeechJsonAndChaptersNeedFrom(): void
    {
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/pool.json");
        copy(self::FILES . "chapters/ffmetadata/real/m4b_audiobook.ffmeta", "$this->dir/book.ffmeta");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "pool.json", "--to", "srt", "-o", "-"]);
        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringContainsString("pool.json: UnknownFormatException (Error #106): Format detection found no subtitle format.", $stderr);
        $this->assertSame(3, $this->runBinary(["convert", "book.ffmeta", "--to", "srt", "-o", "-"])[0]);

        $this->assertSame(
            [0, Subtitle::load("$this->dir/pool.json", Format::Deepgram)->toString(Format::SubRip), ""],
            $this->runBinary(["convert", "pool.json", "--from", "deepgram", "--to", "srt", "-o", "-"])
        );
        $this->assertSame(
            [0, Subtitle::load("$this->dir/book.ffmeta", Format::FfMetadataChapters)->toString(Format::YouTubeChapters), ""],
            $this->runBinary(["convert", "book.ffmeta", "--from", "ffmeta-chapters", "--to", "youtube-chapters", "-o", "-"])
        );
    }


    public function testLenientEncodingLineEndingAndBomOptions(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "broken.srt", "--to", "srt", "-o", "-", "--lenient", "--line-ending", "crlf", "--no-bom"]);

        $this->assertSame(0, $code);
        $this->assertSame("1\r\n00:00:01,000 --> 00:00:02,000\r\nFirst cue.\r\n\r\n2\r\n00:00:05,000 --> 00:00:06,000\r\nLast cue.\r\n", $stdout);
        $this->assertSame("broken.srt: line 5: Block #1 doesn't seem to have its timestamps on its second line! (skipped)\n", $stderr);

        [$code, $stdout] = $this->runBinary(["convert", "latin1.srt", "--encoding", "Windows-1252", "--to", "vtt", "-o", "-"]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith(self::BOM . "WEBVTT\n", $stdout);
        $this->assertStringContainsString("Caf\u{e9} au lait.", $stdout);

        [$code, $stdout] = $this->runBinary(["convert", "shop.vtt", "--to", "sbv", "-o", "-", "--bom"]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith(self::BOM . "0:00:10.000,0:00:12.000\n", $stdout);
    }


    public function testMicroDvdNeedsTheFrameRate(): void
    {
        [$code, , $stderr] = $this->runBinary(["convert", "frames.sub", "--to", "srt", "-o", "-"]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString("The frame rate is unknown.", $stderr);

        [$code, $stdout] = $this->runBinary(["convert", "frames.sub", "--to", "srt", "-o", "-", "--fps", "25"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:03,000\nHello from the frames.\n", $stdout);

        $this->assertSame([0, "{25}{75}Hello from the frames.\n{100}{150}{y:i}Second line.\n", ""],
                          $this->runBinary(["retime", "frames.sub", "--shift", "0", "--fps", "25"]));

        $this->assertSame([3, "", "trip.srt: MicroDVD output needs the frame rate of the video. Pass --fps or --output-fps.\n"],
                          $this->runBinary(["convert", "trip.srt", "--to", "microdvd", "-o", "-"]));
        $this->assertSame(0, $this->runBinary(["convert", "trip.srt", "--to", "microdvd", "-o", "trip.sub", "--fps", "23.976"])[0]);
        $this->assertStringStartsWith("{24}{72}", $this->file("trip.sub"));
    }


    public function testFromAndToAlwaysNameFormats(): void
    {
        $this->assertSame(
            [2, "", "Error: Unknown format \"25\". Run \"subtitle-toolbox formats\" for the list.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            $this->runBinary(["retime", "trip.srt", "--from", "25", "--to", "23.976"])
        );
        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runBinary(["retime", "trip.srt", "--shift", "0", "--from", "srt", "--to", "vtt"]));
    }


    public function testInputAndOutputFrameRatesAreSeparate(): void
    {
        $this->assertSame([0, "{24}{72}Hello from the frames.\n{96}{144}{y:i}Second line.\n", ""],
                          $this->runBinary(["convert", "frames.sub", "--input-fps", "25", "--output-fps", "23.976", "--to", "microdvd", "-o", "-"]));
        $this->assertSame([0, "{24}{72}Hello from the frames.\n{96}{144}{y:i}Second line.\n", ""],
                          $this->runBinary(["convert", "frames.sub", "--fps", "25", "--output-fps", "23.976", "--to", "microdvd", "-o", "-"]));
        $this->assertSame([0, "{25}{75}Hello from the frames.\n{100}{150}{y:i}Second line.\n", ""],
                          $this->runBinary(["convert", "frames.sub", "--fps", "25", "--to", "microdvd", "-o", "-"]));
        $this->assertSame(3, $this->runBinary(["convert", "frames.sub", "--output-fps", "25", "--to", "microdvd", "-o", "-"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "frames.sub", "--input-fps", "0", "--to", "srt", "-o", "-"])[0]);
    }


    public function testConvertReadsATrackOfAnMkvFile(): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        copy(self::FILES . "mkv/seek_head.mkv", "$this->dir/one.webm");
        $mkv = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");

        $this->assertSame([0, "movie.mkv -> out/movie.srt\n", ""], $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "3", "--output-dir", "out"]));
        $this->assertSame($mkv->extract(3)->toString(Format::SubRip), $this->file("out/movie.srt"));

        [$code, $stdout] = $this->runBinary(["convert", "-", "--to", "vtt", "--track", "5"], $this->file("movie.mkv"));
        $this->assertSame([0, $mkv->extract(5)->toString(Format::WebVtt)], [$code, $stdout]);

        $this->assertSame([0, "one.webm -> one.vtt\n", ""], $this->runBinary(["convert", "one.webm", "--to", "vtt", "-o", "one.vtt"]));
        $this->assertSame(MatroskaReader::open(self::FILES . "mkv/seek_head.mkv")->extract(2)->toString(Format::WebVtt),
                          $this->file("one.vtt"));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "movie.mkv", "--to", "srt", "-o", "-"]);
        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith("movie.mkv: InvalidParserException (Error #102): The MKV or WebM file has 6 subtitle tracks. Pass --track N with one of them:\n" .
                                      "  3: S_TEXT/UTF8, de, \"Deutsch (Forced)\", forced\n  4: S_TEXT/ASS, eng, \"English\", default\n", $stderr);
        $this->assertSame(3, $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "7", "-o", "-"])[0]);
        $this->assertSame([3, "", "trip.srt: ParsingException (Error #100): The file is not a Matroska or WebM file.\n"],
                          $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--track", "3", "-o", "-"]));
        $this->assertSame(2, $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "x"])[0]);
    }


    public function testInputOptionsStreamALargeMkvFile(): void
    {
        $file = fopen("$this->dir/large.webm", "wb");
        fwrite($file, file_get_contents(self::FILES . "mkv/seek_head.mkv"));
        fwrite($file, MkvFixtureWriter::id(MkvFixtureWriter::VOID) . MkvFixtureWriter::size(60 << 20));
        $padding = str_repeat("\0", 1 << 20);
        for ($megabyte = 0; $megabyte < 60; $megabyte++) {
            fwrite($file, $padding);
        }
        fclose($file);
        $expected = MatroskaReader::open(self::FILES . "mkv/seek_head.mkv")->extract(2)->toString(Format::SubRip);

        foreach ([["--fps", "25"], ["--input-fps", "25"], ["--word-timestamps"]] as $options) {
            $this->assertSame([0, $expected, ""],
                              $this->runBinary(["convert", "large.webm", "--to", "srt", ...$options], "", "-d", "memory_limit=32M"));
        }
    }


    public function testInputFpsReadsCsvTimesInFrames(): void
    {
        copy(self::FILES . "csv/own_frame_times.csv", "$this->dir/frames.csv");
        $expected = Subtitle::fromString($this->file("frames.csv"), Format::Csv, new ReadOptions(format: new CsvReadOptions(frameRate: 25)));

        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary(["convert", "frames.csv", "--to", "srt", "-o", "-", "--input-fps", "25"]));
        $this->assertSame([0, str_replace("\r\n", "\n", $this->file("frames.csv")), ""],
                          $this->runBinary(["convert", "frames.csv", "--to", "csv", "-o", "-", "--fps", "25", "--no-bom"]));
    }


    public function testInfoListsTheTracksOfAnMkvFile(): void
    {
        copy(self::FILES . "mkv/pgs.mkv", "$this->dir/pgs.mkv");

        $this->assertSame(
            [0, "pgs.mkv\n  Container: matroska\n  Track 3: S_HDMV/PGS, ger, default\n  Track 4: S_HDMV/PGS, eng, default, forced\n", ""],
            $this->runBinary(["info", "pgs.mkv"])
        );

        [$code, $stdout] = $this->runBinary(["info", "pgs.mkv", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame([["file" => "pgs.mkv", "container" => "matroska", "tracks" => [
            ["number" => 3, "codecId" => "S_HDMV/PGS", "language" => "ger", "name" => null, "default" => true, "forced" => false],
            ["number" => 4, "codecId" => "S_HDMV/PGS", "language" => "eng", "name" => null, "default" => true, "forced" => true],
        ]]], json_decode($stdout, true));

        [$code, $stdout] = $this->runBinary(["info", "pgs.mkv", "--track", "4"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Format:", $stdout);
        $this->assertMatchesRegularExpression('/^  Image cues: +\d+, 0 with text$/m', $stdout);
    }


    public function testInfoListsTheTracksOfAnMkvFileOnStandardInput(): void
    {
        $this->assertSame(
            [0, "stdin\n  Container: matroska\n  Track 3: S_HDMV/PGS, ger, default\n  Track 4: S_HDMV/PGS, eng, default, forced\n", ""],
            $this->runBinary(["info", "-"], file_get_contents(self::FILES . "mkv/pgs.mkv"))
        );

        [$code, $stdout] = $this->runBinary(["info", "-", "--json"], file_get_contents(self::FILES . "mkv/pgs.mkv"));
        $this->assertSame([0, "-", [3, 4]], [$code, json_decode($stdout, true)[0]["file"], array_column(json_decode($stdout, true)[0]["tracks"], "number")]);
    }
}
