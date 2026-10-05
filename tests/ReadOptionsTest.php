<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;

class ReadOptionsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    /**
     * @return array<string, array{Format, string, ?FormatReadOptions, float}>
     */
    public static function lastCuesWithoutEnd(): array
    {
        $dubbing = new CsvReadOptions(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character"), frameRate: 25);

        return [
            "TMPlayer"                  => [Format::TmPlayer, "tmplayer/real/tmplayer_crlf.txt", null, 3600.0],
            "LRC"                       => [Format::Lyrics, "lrc/real/handwritten-core.lrc", null, 32.8],
            "SAMI"                      => [Format::Sami, "sami/real/mantas_smi_formatted.smi", null, 17.35],
            "HTML transcript"           => [Format::HtmlTranscript, "html/real/spec_example_shape.html", null, 62.0],
            "Podcasting 2.0 transcript" => [Format::PodcastTranscript, "podcast/real/podcast_transcript_convert_from_html.json", null, 19.0],
            "SubViewer"                 => [Format::SubViewer, "subviewer/real/subviewer1_delay.sub", null, 17.0],
            "CSV"                       => [Format::Csv, "csv/real/dubbing_script.csv", $dubbing, 36008.2],
            "PGS"                       => [Format::Pgs, "pgs/shapes_1080p.sup", null, 20.0],
            "SCC"                       => [Format::Scc, "read-options/last_caption_without_erase.scc", null, 3504.601],
            "VobSub"                    => [Format::VobSub, "vobsub/two-tracks-pal.sub", new VobSubReadOptions(file_get_contents(self::FILES . "vobsub/two-tracks-pal.idx")), 20.0],
        ];
    }


    #[DataProvider("lastCuesWithoutEnd")]
    public function testALastCueWithoutEndLastsFiveSecondsByDefault(Format $format, string $file, ?FormatReadOptions $formatOptions, float $start): void
    {
        $content = file_get_contents(self::FILES . $file);

        $default = Subtitle::fromString($content, $format, new ReadOptions(format: $formatOptions))->getCues();
        $longer  = Subtitle::fromString($content, $format, new ReadOptions(lastCueDuration: 7, format: $formatOptions))->getCues();

        $this->assertSame([$start, round($start + 5, 3)], [end($default)->getStart(), end($default)->getEnd()]);
        $this->assertSame([$start, round($start + 7, 3)], [end($longer)->getStart(), end($longer)->getEnd()]);
    }


    public function testANegativeLastCueDurationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The last cue duration must be 0 or more seconds, got -1.");

        new ReadOptions(lastCueDuration: -1);
    }


    public function testTheLastChapterEndsAtTheMediaDuration(): void
    {
        $content = file_get_contents(self::FILES . "chapters/youtube/real/video_description.txt");
        $cues    = Subtitle::fromString($content, Format::YouTubeChapters, new ReadOptions(format: new ChapterReadOptions(mediaDuration: 3600)))->getCues();

        $this->assertSame(3600.0, end($cues)->getEnd());
    }


    /**
     * @return array<string, array{string, Format, string, ReadOptions}>
     */
    public static function realFilesWithOptions(): array
    {
        return [
            "MicroDVD frame rate"    => ["microdvd_subsrt_sample", Format::MicroDvd, "microdvd/real/subsrt_sample.sub", new ReadOptions(format: new MicroDvdReadOptions(23.976))],
            "encoding"               => ["french_windows_1252", Format::SubRip, "encoding/french-windows-1252.srt", new ReadOptions(encoding: "Windows-1252")],
            "Whisper word times"     => ["whisper_word_timestamps", Format::Whisper, "whisper/real/openai_whisper_word_timestamps.json", new ReadOptions(format: new TranscriptReadOptions(wordTimestamps: true))],
            "WhisperX speakers"      => ["whisperx_speaker_voices", Format::Whisper, "whisper/real/whisperx_diarize.json", new ReadOptions(format: new TranscriptReadOptions(speakerVoices: true))],
            "SAMI language class"    => ["sami_multi_language_frcc", Format::Sami, "sami/real/multi_language.smi", new ReadOptions(lastCueDuration: 10, format: new SamiReadOptions("FRCC"))],
            "CSV delimiter"          => ["csv_excel_de_semicolon", Format::Csv, "csv/real/excel_de_semicolon.csv", new ReadOptions(lastCueDuration: 10, format: new CsvReadOptions(delimiter: ";"))],
            "SCC channel"            => ["scc_rollup_news_ndf", Format::Scc, "scc/real/rollup_news_ndf.scc", new ReadOptions(lastCueDuration: 4, format: new SccReadOptions(channel: 1))],
        ];
    }


    #[DataProvider("realFilesWithOptions")]
    public function testRealFilesGiveTheCuesOf1x(string $golden, Format $format, string $file, ReadOptions $options): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . $file), $format, $options);
        $rows     = array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], array_values($subtitle->getCues()));

        $this->assertSame(json_decode(file_get_contents(self::FILES . "read-options/$golden.json"), true), $rows);
    }


    public function testFormatOptionsOfAnotherFormatThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SubRipParser does not read CsvReadOptions.");

        Subtitle::fromString("1\n00:00:01,000 --> 00:00:02,000\nHello\n", Format::SubRip, new ReadOptions(format: new CsvReadOptions()));
    }
}
