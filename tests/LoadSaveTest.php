<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Matroska\MatroskaTrack;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Exceptions\UnknownFormatException;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Parsers\VobSubReadOptions;

class LoadSaveTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";

    private string $dir;


    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . "/load-save-" . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }


    protected function tearDown(): void
    {
        array_map("unlink", glob("$this->dir/*"));
        rmdir($this->dir);
    }


    /**
     * @return array<string, array{string, Format}>
     */
    public static function autoDetectedFiles(): array
    {
        return [
            "SubRip"    => ["cli/trip.srt", Format::SubRip],
            "WebVTT"    => ["cli/shop.vtt", Format::WebVtt],
            "PGS"       => ["pgs/text_1080p.sup", Format::Pgs],
            "SubViewer" => ["subviewer/real/subviewer2_crlf.sub", Format::SubViewer],
            "iTT"       => ["itt/real/fcp_23976_styles.itt", Format::Itt],
            "TSV"       => ["csv/real/sheets_export.tsv", Format::Tsv],
            "CSV"       => ["csv/real/excel_de_semicolon.csv", Format::Csv],
        ];
    }


    #[DataProvider("autoDetectedFiles")]
    public function testLoadAutoDetectFormatReadsTheFormatOfTheContentOrTheExtension(string $file, Format $format): void
    {
        $subtitle = Subtitle::loadAutoDetectFormat(self::FILES . $file);

        $this->assertSame($format, $subtitle->getFormat());
        $this->assertSame(Subtitle::fromString(file_get_contents(self::FILES . $file), $format)->toArray(), $subtitle->toArray());
    }


    /**
     * @return array<string, array{string, Format}>
     */
    public static function formatsWithoutDetection(): array
    {
        $files = [];
        foreach ([
            "chapters/youtube/real/*.txt"     => Format::YouTubeChapters,
            "chapters/podcast/real/*.json"    => Format::PodcastChapters,
            "chapters/ogm/real/*.txt"         => Format::OgmChapters,
            "chapters/ffmetadata/real/*.ffmeta" => Format::FfMetadata,
            "aws-transcribe/real/*.json"      => Format::AwsTranscribe,
            "deepgram/real/*.json"            => Format::Deepgram,
            "assemblyai/real/*.json"          => Format::AssemblyAi,
            "google-speech/real/*.json"       => Format::GoogleSpeech,
        ] as $pattern => $format) {
            foreach (glob(self::FILES . $pattern) as $path) {
                $files[substr($path, strlen(self::FILES))] = [substr($path, strlen(self::FILES)), $format];
            }
        }

        return $files;
    }


    #[DataProvider("formatsWithoutDetection")]
    public function testLoadReadsFormatsWithoutDetection(string $file, Format $format): void
    {
        $subtitle = Subtitle::load(self::FILES . $file, $format);

        $this->assertSame($format, $subtitle->getFormat());
        $this->assertNotSame([], $subtitle->getCues());
        $this->assertSame(Subtitle::fromString(file_get_contents(self::FILES . $file), $format)->toArray(), $subtitle->toArray());
    }


    /**
     * @return array<string, array{string}>
     */
    public static function filesThatNeedAFormat(): array
    {
        return [
            "FFmpeg metadata"  => ["chapters/ffmetadata/real/m4b_audiobook.ffmeta"],
            "Deepgram JSON"    => ["deepgram/real/pool_utterances_diarize.json"],
            "YouTube chapters" => ["chapters/youtube/real/video_description.txt"],
            "Amazon JSON"      => ["aws-transcribe/real/weather_items_only.json"],
        ];
    }


    #[DataProvider("filesThatNeedAFormat")]
    public function testLoadAutoDetectFormatThrowsForAFormatThatNeedsAFormatArgument(string $file): void
    {
        $this->expectException(UnknownFormatException::class);
        $this->expectExceptionMessage("Call load() with a format.");
        Subtitle::loadAutoDetectFormat(self::FILES . $file);
    }


    public function testMkvTracks(): void
    {
        $mkv = self::FILES . "mkv/text_tracks.mkv";

        $this->assertSame([3, 4, 5, 6, 7, 8], array_map(fn (MatroskaTrack $track): int => $track->number, Subtitle::tracks($mkv)));

        $german = Subtitle::loadTrack($mkv, 3);
        $this->assertSame(Format::SubRip, $german->getFormat());
        $this->assertSame("de", $german->getMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(MatroskaReader::open($mkv)->extract(3)->toArray(), $german->toArray());

        try {
            Subtitle::loadAutoDetectFormat($mkv);
            $this->fail("6 tracks must throw.");
        } catch (InvalidParserException $exception) {
            $this->assertStringContainsString("has 6 subtitle tracks. Call loadTrack() with one of them:\n  3: S_TEXT/UTF8, de", $exception->getMessage());
            $this->assertStringContainsString("\n  8: S_TEXT/UTF8, eng", $exception->getMessage());
        }

        $this->expectException(InvalidParserException::class);
        $this->expectExceptionMessage("Call loadTrack()");
        Subtitle::load($mkv, Format::SubRip);
    }


    public function testLoadAutoDetectFormatReadsTheOnlyTrackOfAnMkvFile(): void
    {
        $subtitle = Subtitle::loadAutoDetectFormat(self::FILES . "mkv/seek_head.mkv");

        $this->assertSame(Format::WebVtt, $subtitle->getFormat());
        $this->assertSame(MatroskaReader::open(self::FILES . "mkv/seek_head.mkv")->extract(2)->toArray(), $subtitle->toArray());
        $this->assertSame($subtitle->toArray(), Subtitle::fromStringAutoDetectFormat(file_get_contents(self::FILES . "mkv/seek_head.mkv"))->toArray());
    }


    public function testPgsTracksOfAnMkvFile(): void
    {
        $subtitle = Subtitle::loadTrack(self::FILES . "mkv/pgs.mkv", 3);
        $this->assertSame(Format::Pgs, $subtitle->getFormat());
        $this->assertNotSame([], $subtitle->getCues());
        $this->assertTrue(Image\CueImage::isImageCue($subtitle->getCues()[0]));

        $this->expectException(InvalidParserException::class);
        $this->expectExceptionMessage("has 2 subtitle tracks. Call loadTrack() with one of them:\n  3: S_HDMV/PGS, ger, default\n  4: S_HDMV/PGS, eng");
        Subtitle::loadAutoDetectFormat(self::FILES . "mkv/pgs.mkv");
    }


    public function testLoadTrackPassesTheLastCueDuration(): void
    {
        $cues = Subtitle::loadTrack(self::FILES . "mkv/text_tracks.mkv", 8, new ReadOptions(lastCueDuration: 2))->getCues();

        $this->assertSame([16.0, 18.0], [end($cues)->getStart(), end($cues)->getEnd()]);
    }


    public function testMicroDvdWithTheFrameRateOfTheOptions(): void
    {
        $subtitle = Subtitle::load(self::FILES . "cli/frames.sub", Format::MicroDvd, new ReadOptions(fps: 25));

        $this->assertSame(Format::MicroDvd, $subtitle->getFormat());
        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(3.0, $subtitle->getCues()[0]->getEnd());
    }


    public function testVobSubReadsTheSubFileNextToTheIdxFile(): void
    {
        $idx = file_get_contents(self::FILES . "vobsub/text-pal.idx");
        $sub = file_get_contents(self::FILES . "vobsub/text-pal.sub");

        $expected = Subtitle::fromString($sub, Format::VobSub, new ReadOptions(format: new VobSubReadOptions($idx)))->toArray();

        $this->assertSame($expected, Subtitle::load(self::FILES . "vobsub/text-pal.idx", Format::VobSub)->toArray());
        $this->assertSame($expected, Subtitle::load(self::FILES . "vobsub/text-pal.sub", Format::VobSub)->toArray());
        $this->assertSame($expected, Subtitle::loadAutoDetectFormat(self::FILES . "vobsub/text-pal.idx")->toArray());
    }


    public function testVobSubFindsTheIdxFileNextToASubFileWithoutExtension(): void
    {
        $idx = file_get_contents(self::FILES . "vobsub/text-pal.idx");
        $sub = file_get_contents(self::FILES . "vobsub/text-pal.sub");
        file_put_contents("$this->dir/movie", $sub);
        file_put_contents("$this->dir/movie.idx", $idx);
        file_put_contents("$this->dir/other", $sub);

        $this->assertSame(
            Subtitle::fromString($sub, Format::VobSub, new ReadOptions(format: new VobSubReadOptions($idx)))->toArray(),
            Subtitle::load("$this->dir/movie", Format::VobSub)->toArray()
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("VobSub needs the .idx file next to $this->dir/other, but $this->dir/other.idx does not exist.");
        Subtitle::load("$this->dir/other", Format::VobSub);
    }


    public function testVobSubKeepsTheTrackAndLanguageOfTheOptions(): void
    {
        $idx  = file_get_contents(self::FILES . "vobsub/two-tracks-pal.idx");
        $sub  = file_get_contents(self::FILES . "vobsub/two-tracks-pal.sub");
        $path = self::FILES . "vobsub/two-tracks-pal.idx";

        $second = Subtitle::load($path, Format::VobSub, new ReadOptions(track: 1));
        $this->assertSame(
            Subtitle::fromString($sub, Format::VobSub, new ReadOptions(track: 1, format: new VobSubReadOptions($idx)))->toArray(),
            $second->toArray()
        );
        $this->assertNotSame(Subtitle::load($path, Format::VobSub)->toArray(), $second->toArray());

        $language = $second->getMetadata(Subtitle::METADATA_LANGUAGE);
        $this->assertSame($second->toArray(), Subtitle::load($path, Format::VobSub, new ReadOptions(language: $language))->toArray());
    }


    public function testSaveSetsTheCsvDelimiterFromTheTargetFormat(): void
    {
        $subtitle = Subtitle::loadAutoDetectFormat(self::FILES . "csv/real/sheets_export.tsv");

        $subtitle->save("$this->dir/x.csv");
        $subtitle->save("$this->dir/x.tsv");

        $this->assertStringContainsString(",", strtok(file_get_contents("$this->dir/x.csv"), "\n"));
        $this->assertStringNotContainsString("\t", file_get_contents("$this->dir/x.csv"));
        $this->assertStringContainsString("\t", strtok(file_get_contents("$this->dir/x.tsv"), "\n"));
        $this->assertSame(
            file_get_contents("$this->dir/x.csv"),
            $subtitle->toString(Format::Csv, new WriteOptions(format: new CsvWriteOptions(delimiter: ",")))
        );

        $semicolons = Subtitle::loadAutoDetectFormat(self::FILES . "csv/real/excel_de_semicolon.csv");
        $this->assertStringContainsString(";", strtok($semicolons->toString(Format::Csv), "\n"));
        $this->assertStringContainsString("\t", strtok($semicolons->toString(Format::Tsv), "\n"));
    }


    public function testSaveTakesTheFormatArgumentOverTheExtension(): void
    {
        $subtitle = Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip);

        $subtitle->save("$this->dir/out.txt", Format::WebVtt);
        $subtitle->save("$this->dir/out.vtt");

        $this->assertSame($subtitle->toString(Format::WebVtt), file_get_contents("$this->dir/out.txt"));
        $this->assertSame($subtitle->toString(Format::WebVtt), file_get_contents("$this->dir/out.vtt"));
    }


    public function testSaveMicroDvdTakesTheFrameRateFromTheOptionsThenFromTheFormatData(): void
    {
        $withFrameRate = Subtitle::load(self::FILES . "cli/frames.sub", Format::MicroDvd, new ReadOptions(fps: 25));
        $withFrameRate->save("$this->dir/x.sub");
        $this->assertSame(
            $withFrameRate->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdWriteOptions(25))),
            file_get_contents("$this->dir/x.sub")
        );
        $withFrameRate->save("$this->dir/y.sub", options: new WriteOptions(format: new MicroDvdWriteOptions(50)));
        $this->assertStringStartsWith("{50}{150}", file_get_contents("$this->dir/y.sub"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MicroDvdWriteOptions::frameRate");
        Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip)->save("$this->dir/z.sub");
    }


    public function testSaveItt(): void
    {
        $itt = Subtitle::loadAutoDetectFormat(self::FILES . "itt/real/fcp_23976_styles.itt");
        $itt->save("$this->dir/x.itt");
        $this->assertSame($itt->toString(Format::Itt), file_get_contents("$this->dir/x.itt"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("IttWriteOptions::frameRate");
        Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip)->save("$this->dir/x.itt");
    }


    public function testGetFormatIsNullForASubtitleThatNoParserRead(): void
    {
        $this->assertNull((new Subtitle())->getFormat());
        $this->assertSame(Format::WebVtt, Subtitle::fromStringAutoDetectFormat("WEBVTT\n\n00:01.000 --> 00:02.000\nHi\n")->getFormat());
    }
}
