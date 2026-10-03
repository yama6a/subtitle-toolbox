<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\AssFormatter;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Formatters\PodcastTranscriptFormatter;
use SubtitleToolbox\Formatters\SamiFormatter;
use SubtitleToolbox\Formatters\SbvFormatter;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Parsers\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class OptionFlagsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    private static function styled(): Subtitle
    {
        return Subtitle::parse(file_get_contents(self::FILES . "srt/real/own_styled.srt"), SubRipParser::class);
    }


    public static function stripTagsFormatters(): array
    {
        return [
            "ASS"      => [AssFormatter::class, []],
            "EBU STL"  => [EbuStlFormatter::class, []],
            "iTT"      => [IttFormatter::class, [IttFormatter::OPTION_FRAME_RATE => 25]],
            "MicroDVD" => [MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25]],
            "SAMI"     => [SamiFormatter::class, []],
            "SubRip"   => [SubRipFormatter::class, []],
            "TTML"     => [TtmlFormatter::class, []],
            "WebVTT"   => [WebVttFormatter::class, []],
        ];
    }


    #[DataProvider("stripTagsFormatters")]
    public function testStripAllXmlTagsWorksAsAKey(string $formatter, array $options): void
    {
        $default = self::styled()->format($formatter, $options);
        $list    = self::styled()->format($formatter, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS]);

        $this->assertNotSame($default, $list);
        $this->assertSame($list, self::styled()->format($formatter, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS => true]));
        $this->assertSame($default, self::styled()->format($formatter, $options + [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS => false]));
    }


    public static function formatterFlags(): array
    {
        $dubbing = fn (): Subtitle => (new CsvParser(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25)))
            ->parse(file_get_contents(self::FILES . "csv/real/dubbing_script.csv"));
        $podcast = fn (): Subtitle => (new PodcastTranscriptParser([PodcastTranscriptParser::OPTION_WORD_TIMESTAMPS => true]))
            ->parse(file_get_contents(self::FILES . "podcast/real/spec_word_segments.json"));
        $scc     = fn (): Subtitle => Subtitle::parse(file_get_contents(self::FILES . "scc/real/rollup_news_ndf.scc"), SccParser::class);
        $styled  = self::styled(...);

        return [
            "BOM"                   => [SbvFormatter::class, SubtitleFormatter::OPTION_BOM, [], $styled],
            "CSV escape formulas"   => [CsvFormatter::class, CsvFormatter::OPTION_ESCAPE_FORMULAS, [], $dubbing],
            "JSON pretty print"     => [JsonFormatter::class, JsonFormatter::OPTION_PRETTY_PRINT, [], $styled],
            "MicroDVD frame rate"   => [MicroDvdFormatter::class, MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE,
                                        [MicroDvdFormatter::OPTION_FRAME_RATE => 25], $styled],
            "plain text with times" => [PlainTextFormatter::class, PlainTextFormatter::OPTION_WITH_TIMES, [], $styled],
            "podcast pretty print"  => [PodcastTranscriptFormatter::class, PodcastTranscriptFormatter::OPTION_PRETTY_PRINT, [], $podcast],
            "podcast word segments" => [PodcastTranscriptFormatter::class, PodcastTranscriptFormatter::OPTION_WORD_SEGMENTS, [], $podcast],
            "SCC drop frame"        => [SccFormatter::class, SccFormatter::OPTION_DROP_FRAME, [], $scc],
        ];
    }


    #[DataProvider("formatterFlags")]
    public function testFormatterFlagWorksAsAListValue(string $formatter, string $flag, array $options, \Closure $subtitle): void
    {
        $key = $subtitle()->format($formatter, $options + [$flag => true]);

        $this->assertNotSame($subtitle()->format($formatter, $options), $key);
        $this->assertSame($key, $subtitle()->format($formatter, $options + [$flag]));
    }


    public function testFlagsThatDefaultToTrueWorkAsAListValue(): void
    {
        foreach ([
            JsonFormatter::class      => JsonFormatter::OPTION_WITH_FORMAT_DATA,
            PlainTextFormatter::class => PlainTextFormatter::OPTION_JOIN_LINES,
        ] as $formatter => $flag) {
            $this->assertSame(self::styled()->format($formatter, [$flag => true]), self::styled()->format($formatter, [$flag]));
        }
        $this->assertSame(
            self::styled()->format(PlainTextFormatter::class, [PlainTextFormatter::OPTION_JOIN_CUES => true]),
            self::styled()->format(PlainTextFormatter::class, [PlainTextFormatter::OPTION_JOIN_CUES])
        );
    }


    public function testSkipImageCuesWorksAsAListValue(): void
    {
        $subtitle = self::styled()->addCue((new CueImage(PngEncoder::encode(1, 1, [0xFFFFFFFF]), 0, 0, 1, 1, 9, 9))
            ->toCue(new SubtitleCue(90, 91)));

        $this->assertSame(
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true]),
            $subtitle->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES])
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
