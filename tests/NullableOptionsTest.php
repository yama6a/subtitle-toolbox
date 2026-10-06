<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Dual\DualSubtitle;
use SubtitleToolbox\Dual\DualSubtitleOptions;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\GlyphOcrOptions;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Ocr\TesseractOcrOptions;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Streaming\CueStreamReader;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\SubRipStreamWriter;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamWriter;

class NullableOptionsTest extends TestCase
{
    private const FILES = __DIR__ . "/files/";


    private static function load(string $file, Format $format): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . $file), $format);
    }


    /**
     * @param \Closure(Subtitle): mixed $apply
     * @return array{mixed, string}
     */
    private static function changed(string $file, Format $format, \Closure $apply): array
    {
        $subtitle = self::load($file, $format);
        $result   = $apply($subtitle);

        return [$result, $subtitle->toString(Format::SubRip)];
    }


    /**
     * @param \Closure(resource, ?WriteOptions=): (SubRipStreamWriter|WebVttStreamWriter) $open
     * @param list<?WriteOptions> $options
     */
    private static function written(\Closure $open, array $options): string
    {
        $stream = fopen("php://memory", "w+b");
        $writer = $open($stream, ...$options);
        foreach (self::load("srt/real/own_styled.srt", Format::SubRip)->getCues() as $cue) {
            $writer->write($cue);
        }
        $writer->close();
        rewind($stream);

        return stream_get_contents($stream);
    }


    /**
     * @return array{list<SubtitleCue>, list<ParseWarning>}
     */
    private static function streamed(CueStreamReader $reader, string $file): array
    {
        return [iterator_to_array($reader->read(self::FILES . $file), false), $reader->getWarnings()];
    }


    /**
     * @return iterable<string, array{\Closure(array): mixed, object}>
     */
    public static function calls(): iterable
    {
        $srt = "srt/real/own_styled.srt";

        yield "Subtitle::toString" => [
            fn (array $o): string => self::load($srt, Format::SubRip)->toString(Format::WebVtt, ...$o),
            new WriteOptions(),
        ];
        yield "Subtitle::replaceText" => [
            fn (array $o): string => self::load($srt, Format::SubRip)->replaceText("e", "E", ...$o)->toString(Format::SubRip),
            new ReplaceTextOptions(),
        ];
        yield "Subtitle::mergeShortCues" => [
            fn (array $o): string => self::load("short-cues/own_speech_to_text.srt", Format::SubRip)->mergeShortCues(...$o)
                                                                                                     ->toString(Format::SubRip),
            new MergeShortCuesOptions(),
        ];
        yield "SubRipParser::parse" => [
            fn (array $o): string => (new SubRipParser())->parse(file_get_contents(self::FILES . $srt), ...$o)->toString(Format::SubRip),
            new ReadOptions(),
        ];
        yield "HlsWebVttSegmenter::segment" => [
            function (array $o): array {
                $rendition = HlsWebVttSegmenter::segment(self::load("hls/node-webvtt-subs1.vtt", Format::WebVtt), ...$o);

                return [$rendition->getPlaylist(), iterator_to_array($rendition->getSegments())];
            },
            new HlsSegmentOptions(),
        ];
        yield "DualSubtitle::fromPair" => [
            fn (array $o): string => DualSubtitle::fromPair(self::load("dual/station_en.srt", Format::SubRip),
                                                            self::load("dual/station_de.srt", Format::SubRip), ...$o)
                                                  ->toString(Format::SubRip),
            new DualSubtitleOptions(),
        ];
        yield "CommonErrorFixer::apply" => [
            fn (array $o): array => self::changed("fixing/web-errors.srt", Format::SubRip,
                                                  fn (Subtitle $s) => CommonErrorFixer::apply($s, ...$o)),
            new CommonErrorOptions(),
        ];
        yield "CommonErrorFixer::preview" => [
            fn (array $o): array => self::changed("fixing/web-errors.srt", Format::SubRip,
                                                  fn (Subtitle $s) => CommonErrorFixer::preview($s, ...$o)),
            new CommonErrorOptions(),
        ];
        yield "HearingImpairedRemover::apply" => [
            fn (array $o): array => self::changed("hearing-impaired/own_sdh.srt", Format::SubRip,
                                                  fn (Subtitle $s) => HearingImpairedRemover::apply($s, ...$o)),
            new HearingImpairedOptions(),
        ];
        yield "HearingImpairedRemover::isAnnotation" => [
            fn (array $o): array => array_map(fn (string $line): bool => HearingImpairedRemover::isAnnotation($line, ...$o),
                                              ["JOHN: Hi.", "[DOOR SLAMS]", "# The wheels go round #", "Hi."]),
            new HearingImpairedOptions(),
        ];
        yield "WordHighlight::apply" => [
            fn (array $o): array => self::changed("karaoke/hebrew.lrc", Format::Lyrics,
                                                  fn (Subtitle $s) => WordHighlight::apply($s, ...$o)),
            new WordHighlightOptions(),
        ];
        yield "SubRipStreamWriter::__construct" => [
            fn (array $o): string => self::written(fn ($stream, ...$o) => new SubRipStreamWriter($stream, ...$o), $o),
            new WriteOptions(),
        ];
        yield "WebVttStreamWriter::__construct" => [
            fn (array $o): string => self::written(fn ($stream, ...$o) => new WebVttStreamWriter($stream, ...$o), $o),
            new WriteOptions(),
        ];
        yield "SubRipStreamReader::__construct" => [
            fn (array $o): array => self::streamed(new SubRipStreamReader(...$o), $srt),
            new ReadOptions(),
        ];
        yield "WebVttStreamReader::__construct" => [
            fn (array $o): array => self::streamed(new WebVttStreamReader(...$o), "vtt/real/w3c_regions.vtt"),
            new ReadOptions(),
        ];
        yield "GlyphOcrEngine::__construct" => [
            fn (array $o): string => (new PgsParser())->parse(file_get_contents(self::FILES . "pgs/text_1080p.sup"))
                                                      ->recognizeText(new GlyphOcrEngine(...$o))
                                                      ->toString(Format::SubRip),
            new GlyphOcrOptions(),
        ];
        yield "TesseractOcrEngine::__construct" => [
            fn (array $o): TesseractOcrOptions => (new \ReflectionProperty(TesseractOcrEngine::class, "options"))
                ->getValue(new TesseractOcrEngine(...$o)),
            new TesseractOcrOptions(),
        ];
    }


    #[DataProvider("calls")]
    public function testNoOptionsAndNullEqualTheDefaultOptions(\Closure $call, object $default): void
    {
        $expected = $call([$default]);

        $this->assertEquals($expected, $call([]));
        $this->assertEquals($expected, $call([null]));
    }


    public function testEveryFormatterAcceptsNullOptions(): void
    {
        $formatters = 0;
        foreach (glob(__DIR__ . "/../src/Formatters/*Formatter.php") as $file) {
            $class = new \ReflectionClass("SubtitleToolbox\\Formatters\\" . basename($file, ".php"));
            if (!$class->isSubclassOf(SubtitleFormatter::class) && $class->name !== SubtitleFormatter::class) {
                continue;
            }
            $options = $class->getMethod("format")->getParameters()[1];
            $this->assertTrue($options->allowsNull(), "$class->name::format()");
            $this->assertNull($options->getDefaultValue(), "$class->name::format()");
            $formatters++;
        }
        $this->assertGreaterThan(25, $formatters);
    }


    public function testFormatterWithoutOptionsWritesTheDefaultOutput(): void
    {
        $subtitle  = self::load("srt/real/own_styled.srt", Format::SubRip);
        $formatter = new Formatters\WebVttFormatter();

        $this->assertSame($formatter->format($subtitle, new WriteOptions()), $formatter->format($subtitle));
        $this->assertSame($formatter->format($subtitle, new WriteOptions()), $formatter->format($subtitle, null));
    }
}
