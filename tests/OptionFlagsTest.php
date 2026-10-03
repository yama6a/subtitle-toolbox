<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Formatters\PodcastTranscriptFormatter;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Parsers\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class OptionFlagsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    private static function styled(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "srt/real/own_styled.srt"), Format::SubRip);
    }


    public static function stripTagsFormatters(): array
    {
        return [
            "ASS"      => [Format::Ass, []],
            "EBU STL"  => [Format::EbuStl, []],
            "iTT"      => [Format::Itt, [IttFormatter::OPTION_FRAME_RATE => 25]],
            "MicroDVD" => [Format::MicroDvd, [MicroDvdFormatter::OPTION_FRAME_RATE => 25]],
            "SAMI"     => [Format::Sami, []],
            "SubRip"   => [Format::SubRip, []],
            "TTML"     => [Format::Ttml, []],
            "WebVTT"   => [Format::WebVtt, []],
        ];
    }


    #[DataProvider("stripTagsFormatters")]
    public function testStripAllXmlTagsWorksAsAKey(Format $format, array $options): void
    {
        $default = self::styled()->toString($format, $options);
        $list    = self::styled()->toString($format, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS]);

        $this->assertNotSame($default, $list);
        $this->assertSame($list, self::styled()->toString($format, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS => true]));
        $this->assertSame($default, self::styled()->toString($format, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS => false]));
    }


    public static function formatterFlags(): array
    {
        $dubbing = fn (): Subtitle => (new CsvParser(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25)))
            ->parse(file_get_contents(self::FILES . "csv/real/dubbing_script.csv"));
        $podcast = fn (): Subtitle => (new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_WORD_TIMESTAMPS => true]))
            ->parse(file_get_contents(self::FILES . "podcast/real/spec_word_segments.json"));
        $scc     = fn (): Subtitle => Subtitle::fromString(file_get_contents(self::FILES . "scc/real/rollup_news_ndf.scc"), Format::Scc);
        $styled  = self::styled(...);

        return [
            "BOM"                   => [Format::Sbv, SubtitleFormatter::OPTION_BOM, [], $styled],
            "CSV escape formulas"   => [Format::Csv, CsvFormatter::OPTION_ESCAPE_FORMULAS, [], $dubbing],
            "JSON pretty print"     => [Format::Json, JsonFormatter::OPTION_PRETTY_PRINT, [], $styled],
            "MicroDVD frame rate"   => [Format::MicroDvd, MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE,
                                        [MicroDvdFormatter::OPTION_FRAME_RATE => 25], $styled],
            "plain text with times" => [Format::PlainText, PlainTextFormatter::OPTION_WITH_TIMES, [], $styled],
            "podcast pretty print"  => [Format::PodcastTranscript, PodcastTranscriptFormatter::OPTION_PRETTY_PRINT, [], $podcast],
            "podcast word segments" => [Format::PodcastTranscript, PodcastTranscriptFormatter::OPTION_WORD_SEGMENTS, [], $podcast],
            "SCC drop frame"        => [Format::Scc, SccFormatter::OPTION_DROP_FRAME, [], $scc],
        ];
    }


    #[DataProvider("formatterFlags")]
    public function testFormatterFlagWorksAsAListValue(Format $format, string $flag, array $options, \Closure $subtitle): void
    {
        $key = $subtitle()->toString($format, $options + [$flag => true]);

        $this->assertNotSame($subtitle()->toString($format, $options), $key);
        $this->assertSame($key, $subtitle()->toString($format, $options + [$flag]));
    }


    public function testFlagsThatDefaultToTrueWorkAsAListValue(): void
    {
        foreach ([
            [Format::Json, JsonFormatter::OPTION_WITH_FORMAT_DATA],
            [Format::PlainText, PlainTextFormatter::OPTION_JOIN_LINES],
        ] as [$format, $flag]) {
            $this->assertSame(self::styled()->toString($format, [$flag => true]), self::styled()->toString($format, [$flag]));
        }
        $this->assertSame(
            self::styled()->toString(Format::PlainText, [PlainTextFormatter::OPTION_JOIN_CUES => true]),
            self::styled()->toString(Format::PlainText, [PlainTextFormatter::OPTION_JOIN_CUES])
        );
    }


    public function testSkipImageCuesWorksAsAListValue(): void
    {
        $subtitle = self::styled()->addCue((new CueImage(PngEncoder::encode(1, 1, [0xFFFFFFFF]), 0, 0, 1, 1, 9, 9))
            ->toCue(new SubtitleCue(90, 91)));

        $this->assertSame(
            $subtitle->toString(Format::SubRip, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true]),
            $subtitle->toString(Format::SubRip, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES])
        );
    }


    public static function parserFlags(): array
    {
        return [
            "Whisper word timestamps" => [WhisperJsonParser::class, WhisperJsonParser::OPTION_WORD_TIMESTAMPS, "whisper/real/openai_whisper_word_timestamps.json"],
            "Whisper speaker voices"  => [WhisperJsonParser::class, WhisperJsonParser::OPTION_SPEAKER_VOICES, "whisper/real/whisperx_diarize.json"],
            "Deepgram speaker voices" => [DeepgramParser::class, DeepgramParser::OPTION_SPEAKER_VOICES, "deepgram/real/pool_utterances_diarize.json"],
            "YouTube word timestamps" => [YouTubeTimedTextParser::class, YouTubeTimedTextParser::OPTION_WORD_TIMESTAMPS, "youtube/real/auto.en.json3"],
            "podcast keep segments"   => [PodcastTranscriptParser::class, PodcastTranscriptParser::OPTION_KEEP_SEGMENTS, "podcast/real/spec_word_segments.json"],
            "podcast word timestamps" => [PodcastTranscriptParser::class, PodcastTranscriptParser::OPTION_WORD_TIMESTAMPS, "podcast/real/spec_word_segments.json"],
        ];
    }


    #[DataProvider("parserFlags")]
    public function testParserFlagWorksAsAListValue(string $parser, string $flag, string $file): void
    {
        $content = file_get_contents(self::FILES . $file);
        $key     = (new $parser([$flag => true]))->parse($content)->toArray();

        $this->assertNotSame((new $parser())->parse($content)->toArray(), $key);
        $this->assertSame($key, (new $parser([$flag]))->parse($content)->toArray());
    }
}
