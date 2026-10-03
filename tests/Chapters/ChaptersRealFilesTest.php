<?php

namespace SubtitleToolbox\Chapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\FfMetadataChaptersFormatter;
use SubtitleToolbox\Formatters\OgmChaptersFormatter;
use SubtitleToolbox\Formatters\PodcastChaptersFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\YouTubeChaptersFormatter;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\YouTubeChaptersParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class ChaptersRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/chapters/";

    private const BYTES = [SubtitleFormatter::OPTION_LINE_ENDING => "\n"];


    public static function realFiles(): array
    {
        return [
            "Podcasting basic spec example" => [
                "podcast/real/spec_basic_example.json", PodcastChaptersParser::class, PodcastChaptersFormatter::class,
                8, [0.0, 168.0, "Intro"], [6089.0, 6089.0, "Outro"], null,
            ],
            "Podcasting complex spec example" => [
                "podcast/real/spec_complex_example.json", PodcastChaptersParser::class, PodcastChaptersFormatter::class,
                9, [0.0, 168.0, "Intro"], [6089.0, 6089.0, "Outro"], null,
            ],
            "Podcasting host export" => [
                "podcast/real/hosting_export.json", PodcastChaptersParser::class, PodcastChaptersFormatter::class,
                5, [0.0, 95.5, "Welcome"], [1530.0, 1804.8, "Listener questions"], null,
            ],
            "FFmpeg MP4 export" => [
                "ffmetadata/real/ffmpeg_mp4_export.ffmeta", FfMetadataChaptersParser::class, FfMetadataChaptersFormatter::class,
                4, [0.0, 184.52, "Welcome and agenda"], [2210.48, 2405.007, "Wrap-up"], self::BYTES,
            ],
            "FFmpeg M4B audiobook" => [
                "ffmetadata/real/m4b_audiobook.ffmeta", FfMetadataChaptersParser::class, FfMetadataChaptersFormatter::class,
                3, [0.0, 30.0, "Opening credits"], [940.0, 2236.0, "Chapter 2=Starters"], self::BYTES,
            ],
            "FFmpeg hand-written CR LF" => [
                "ffmetadata/real/hand_written_crlf.ffmeta", FfMetadataChaptersParser::class, FfMetadataChaptersFormatter::class,
                3, [0.0, 90.0, "Doors open"], [1950.5, 1950.5, "Talk 2"], null,
            ],
            "OGM from mkvextract" => [
                "ogm/real/mkvextract_simple.txt", OgmChaptersParser::class, OgmChaptersFormatter::class,
                5, [0.0, 92.48, "Opening"], [3130.04, 3130.04, "End credits"], self::BYTES,
            ],
            "OGM with BOM and CR LF" => [
                "ogm/real/windows_tool_crlf_bom.txt", OgmChaptersParser::class, OgmChaptersFormatter::class,
                6, [0.0, 300.0, "Chapter 01"], [1500.0, 1500.0, "Chapter 06"],
                [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n", SubtitleFormatter::OPTION_BOM => true],
            ],
            "YouTube video description" => [
                "youtube/real/video_description.txt", YouTubeChaptersParser::class, YouTubeChaptersFormatter::class,
                7, [0.0, 45.0, "Intro"], [1660.0, 1660.0, "Final check and test ride"], null,
            ],
            "YouTube long stream" => [
                "youtube/real/long_stream_chapters.txt", YouTubeChaptersParser::class, YouTubeChaptersFormatter::class,
                7, [0.0, 312.0, "Stream starts"], [13870.0, 13870.0, "Goodbye"], null,
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $file, string $parserClass, string $formatterClass, int $count, array $first, array $last): void
    {
        $cues = $this->parse($file, $parserClass)->getCues();

        $this->assertCount($count, $cues);
        $this->assertSame($first, $this->describe($cues[0]));
        $this->assertSame($last, $this->describe($cues[$count - 1]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $file, string $parserClass, string $formatterClass): void
    {
        $subtitle = $this->parse($file, $parserClass);
        $again    = Subtitle::parse($subtitle->format($formatterClass), $parserClass);

        $this->assertSame(array_map($this->describe(...), $subtitle->getCues()), array_map($this->describe(...), $again->getCues()));
        $this->assertSame($subtitle->getAllMetadata(), $again->getAllMetadata());
        $this->assertEquals($subtitle->getCues(), $again->getCues());
    }


    public static function byteExactFiles(): array
    {
        return array_filter(self::realFiles(), fn (array $file): bool => $file[6] !== null);
    }


    #[DataProvider("byteExactFiles")]
    public function testRealFileFormatsToItsOwnBytes(string $file, string $parserClass, string $formatterClass, int $count, array $first, array $last, array $options): void
    {
        $content = file_get_contents(self::DIR . $file);

        $this->assertSame($content, $this->parse($file, $parserClass)->format($formatterClass, $options));
    }


    public function testComplexSpecExampleKeepsTheChapterFields(): void
    {
        $subtitle = $this->parse("podcast/real/spec_complex_example.json", PodcastChaptersParser::class);
        $silent   = $subtitle->getCues()[5];

        $this->assertSame("Episode 7 - Making Progress", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("John Doe", $subtitle->getMetadata(Subtitle::METADATA_AUTHOR));
        $this->assertSame(["version" => "1.2.0", "podcastName" => "John's Awesome Podcast"], $subtitle->getFormatData("chapters"));
        $this->assertSame([], $silent->getLines());
        $this->assertSame([
            "img"      => "https://example.com/images/parisfrance.jpg",
            "toc"      => false,
            "location" => ["name" => "Eiffel Tower, Paris", "geo" => "geo:42.3417649,-70.9661596"],
        ], $silent->getFormatData("chapters"));
    }


    public function testHostExportWritesOnlyTheEndTimeThatDiffersFromTheNextStart(): void
    {
        $subtitle = $this->parse("podcast/real/hosting_export.json", PodcastChaptersParser::class);
        $json     = json_decode($subtitle->format(PodcastChaptersFormatter::class), true);

        $this->assertSame("Sanding &amp; painting the hull", $subtitle->getCues()[2]->getText());
        $this->assertSame("Sanding & painting the hull", $json["chapters"][2]["title"]);
        $this->assertSame(["startTime" => 1490, "toc" => false, "img" => "https://media.example.com/harbour-notes/ep12/sponsor.png"], $json["chapters"][3]);
        $this->assertSame(1804.8, $json["chapters"][4]["endTime"]);
    }


    public function testAudiobookKeepsTheStreamAndTheTimeBase(): void
    {
        $subtitle = $this->parse("ffmetadata/real/m4b_audiobook.ffmeta", FfMetadataChaptersParser::class);

        $this->assertSame("Read by the author\nRecorded in 2023", $subtitle->getFormatData("ffmetadata")["tags"]["comment"]);
        $this->assertSame([["handler_name" => "SoundHandler", "vendor_id" => "[0][0][0][0]"]], $subtitle->getFormatData("ffmetadata")["streams"]);
        $this->assertSame("Jane Doe", $subtitle->getMetadata(Subtitle::METADATA_ARTIST));
        $this->assertSame(["timeBase" => "1/44100", "tags" => []], $subtitle->getCues()[1]->getFormatData("ffmetadata"));
    }


    public function testMediaDurationEndsTheLastChapter(): void
    {
        $content = file_get_contents(self::DIR . "youtube/real/long_stream_chapters.txt");
        $cues    = (new YouTubeChaptersParser(mediaDuration: 14400))->parse($content)->getCues();

        $this->assertSame([13870.0, 14400.0, "Goodbye"], $this->describe($cues[6]));
    }


    private function parse(string $file, string $parserClass): Subtitle
    {
        return Subtitle::parse(file_get_contents(self::DIR . $file), $parserClass);
    }


    private function describe(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getText()];
    }
}
