<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Container\Matroska\MkvFixtureWriter;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\DualSubtitleOptions;
use SubtitleToolbox\Encoding\Cea608;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\Formatters\Options\CsvWriteOptions;
use SubtitleToolbox\Formatters\Options\EbuStlWriteOptions;
use SubtitleToolbox\Formatters\Options\HtmlTranscriptWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\Formatters\Options\PlainTextWriteOptions;
use SubtitleToolbox\Formatters\Options\SubViewerWriteOptions;
use SubtitleToolbox\Formatters\PgsFormatter;
use SubtitleToolbox\Formatters\SccFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\HearingImpairedOptions;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\Hls\TimestampMap;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PaletteReducer;
use SubtitleToolbox\Image\PngDecoder;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\OcrResult;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Parsers\HtmlTranscriptParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\Parsers\Options\SccReadOptions;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\Options\VobSubReadOptions;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\ResegmentMode;
use SubtitleToolbox\ResegmentOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\SubRipStreamWriter;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Sync\ReferenceSyncOptions;
use SubtitleToolbox\Sync\SpeechReference;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Translation\TranslationEngine;
use SubtitleToolbox\Translation\TranslationOptions;
use SubtitleToolbox\Translation\TranslationRunner;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\WriteOptions;

require_once __DIR__ . "/../files/mkv/generator/MkvFixtureWriter.php";

class ThrowSitesTest extends TestCase
{
    private const SRC = __DIR__ . "/../../src/";

    private const CODES = [
        ParsingException::class             => 100,
        InvalidFormatterException::class    => 101,
        InvalidParserException::class       => 102,
        ImageCueWithoutTextException::class => 103,
        InvalidArgumentException::class     => 104,
        CueNotFoundException::class         => 105,
        UnknownFormatException::class       => 106,
    ];

    private const IDX = "# VobSub index file, v7 (do not modify this line!)\nsize: 720x576\n" .
                        "palette: 000000, f0f0f0, cccccc, 999999, 3333fa, 1111bb, fa3333, bb1111, " .
                        "33fa33, 11bb11, fafa33, bbbb11, fa33fa, bb11bb, 33fafa, 11bbbb\n";

    private const IDX_WITH_TRACK = self::IDX . "id: en, index: 0\ntimestamp: 00:00:01:000, filepos: 000000000\n";

    private const FILES = __DIR__ . "/../files/";

    private const FAKE_TESSERACT = __DIR__ . "/../files/ocr/fake-tesseract/tesseract";

    private const TTML = '<tt xmlns="http://www.w3.org/ns/ttml"><body><div>%s</div></body></tt>';


    private static function pgsSegment(int $type, string $data): string
    {
        return "PG" . pack("NNCn", 0, 0, $type, strlen($data)) . $data;
    }


    private static function png(): string
    {
        return PngEncoder::encode(1, 1, [0xFFFFFFFF]);
    }


    private static function pngChunk(string $type, string $data): string
    {
        return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    }


    private static function pngWithIhdr(int $interlace): string
    {
        return "\x89PNG\r\n\x1a\n" . self::pngChunk("IHDR", pack("NNCCCCC", 1, 1, 8, 6, 0, 0, $interlace));
    }


    private static function vobSubPacket(string $unit): string
    {
        $body = "\x81\x00\x00\x20" . $unit;

        return "\x00\x00\x01\xBA\x44\x00\x04\x00\x04\x01\x01\x89\xC3\xFA\xFF\xFF\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
    }


    private static function unreadableFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), "unreadable");
        chmod($path, 0);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }


    private static function closedWriter(): SubRipStreamWriter
    {
        $writer = new SubRipStreamWriter(fopen("php://memory", "wb"));
        $writer->close();

        return $writer;
    }


    /**
     * @return resource
     */
    private static function stream(string $content)
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }


    /**
     * Opens an MKV file with one S_TEXT/UTF8 track 2, the given track fields and the given segment data after the Tracks element.
     */
    private static function mkv(string $clusters, array $track = [], string $cut = ""): MatroskaReader
    {
        $tracks = MkvFixtureWriter::element(MkvFixtureWriter::TRACKS, MkvFixtureWriter::trackEntry($track + [
            "number" => 2, "type" => MkvFixtureWriter::TRACK_SUBTITLE, "codecId" => "S_TEXT/UTF8",
        ]));
        $file   = MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, $tracks . $clusters);

        return MatroskaReader::open(self::stream($cut === "" ? $file : substr($file, 0, -strlen($cut))));
    }


    private static function fromArray(array $data): Subtitle
    {
        return Subtitle::fromArray($data + ["version" => Subtitle::ARRAY_VERSION, "cues" => []]);
    }


    private static function subtitle(): Subtitle
    {
        return (new Subtitle())->addCue(new SubtitleCue(1, 2, ["first line", "second line"]));
    }


    /**
     * Keys start with the file under src/ that holds the throw site, so that the coverage test can count them.
     *
     * @return array<string, array{Closure, class-string, class-string}>
     */
    public static function throwSites(): array
    {
        $invalid  = [\InvalidArgumentException::class, InvalidArgumentException::class];
        $parsing  = [ParsingException::class, ParsingException::class];
        $imageCue = (new CueImage("png", 0, 0, 1, 1, 1, 1))->toCue(new SubtitleCue(1, 2));

        $cue = ["start" => 1, "end" => 2, "lines" => ["text"]];

        return [
            "ArrayConversion.php: no version"               => [fn () => Subtitle::fromArray([]), ...$parsing],
            "ArrayConversion.php: unknown version"          => [fn () => Subtitle::fromArray(["version" => 9]), ...$parsing],
            "ArrayConversion.php: metadata no string"       => [fn () => self::fromArray(["metadata" => ["title" => 5]]), ...$parsing],
            "ArrayConversion.php: cues no list"             => [fn () => Subtitle::fromArray(["version" => Subtitle::ARRAY_VERSION]), ...$parsing],
            "ArrayConversion.php: comment no object"        => [fn () => self::fromArray(["comments" => ["note"]]), ...$parsing],
            "ArrayConversion.php: comment text"             => [fn () => self::fromArray(["comments" => [["beforeCueIndex" => 0]]]), ...$parsing],
            "ArrayConversion.php: comment index"            => [fn () => self::fromArray(["comments" => [["text" => "note"]]]), ...$parsing],
            "ArrayConversion.php: cue no object"            => [fn () => self::fromArray(["cues" => [5]]), ...$parsing],
            "ArrayConversion.php: cue start"                => [fn () => self::fromArray(["cues" => [["start" => "1"] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue lines"                => [fn () => self::fromArray(["cues" => [["lines" => "text"] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue line"                 => [fn () => self::fromArray(["cues" => [["lines" => [5]] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue identifier"           => [fn () => self::fromArray(["cues" => [["identifier" => 5] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue alignment"            => [fn () => self::fromArray(["cues" => [["alignment" => 10] + $cue]]), ...$parsing],
            "ArrayConversion.php: cue forced"               => [fn () => self::fromArray(["cues" => [["forced" => 1] + $cue]]), ...$parsing],
            "ArrayConversion.php: format data no object"    => [fn () => self::fromArray(["formatData" => ["srt" => 5]]), ...$parsing],
            "ArrayConversion.php: map no object"            => [fn () => self::fromArray(["metadata" => 5]), ...$parsing],
            "ArrayConversion.php: comments no list"         => [fn () => self::fromArray(["comments" => 5]), ...$parsing],
            "Cli/Command.php: unknown option"               => [fn () => Arguments::parse(["--nope"], []), ...$invalid],
            "Container/Matroska/EbmlReader.php: invalid element header" => [fn () => MatroskaReader::open(self::stream("\0\0\0\0")), ...$parsing],
            "Container/Matroska/EbmlReader.php: cut off element data" => [fn () => self::mkv("", ["codecPrivate" => "abc"], "c"), ...$parsing],
            "Container/Matroska/MatroskaReader.php: stream not seekable" => [fn () => MatroskaReader::open(fopen("php://output", "wb")), ...$invalid],
            "Container/Matroska/MatroskaReader.php: unknown track" => [fn () => self::mkv("")->extract(9), ...$invalid],
            "Container/Matroska/MatroskaReader.php: unsupported codec" => [fn () => self::mkv("", ["codecId" => "S_VOBSUB"])->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: not Matroska" => [fn () => MatroskaReader::open(self::stream(MkvFixtureWriter::ebmlHeader("avi"))),
                                                                ...$parsing],
            "Container/Matroska/MatroskaReader.php: no Tracks" => [fn () => MatroskaReader::open(self::stream(MkvFixtureWriter::ebmlHeader() .
                                                                MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT, MkvFixtureWriter::info()))), ...$parsing],
            "Container/Matroska/MatroskaReader.php: invalid block header" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::element(0xA3, "\0\0\0\0")]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: laced subtitle block" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::simpleBlock(2, 0, "\0x", MkvFixtureWriter::LACING_XIPH)]))->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: bzlib compression" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::blockGroup(2, 0, "BZh9", 1000)]),
                                                                ["encodings" => MkvFixtureWriter::compression(0, 1)])->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: invalid zlib data" => [fn () => self::mkv(MkvFixtureWriter::cluster(0, [
                                                                MkvFixtureWriter::blockGroup(2, 0, "not zlib", 1000)]),
                                                                ["encodings" => MkvFixtureWriter::compression(0, 0)])->extract(2), ...$parsing],
            "Container/Matroska/MatroskaReader.php: child larger than its parent" => [fn () => MatroskaReader::open(self::stream(
                                                                "\x1A\x45\xDF\xA3\x84\x42\x82\x88matroska")), ...$parsing],
            "Container/Matroska/MatroskaReader.php: unknown size of Tracks" => [fn () => MatroskaReader::open(self::stream(
                                                                MkvFixtureWriter::ebmlHeader() . MkvFixtureWriter::element(MkvFixtureWriter::SEGMENT,
                                                                MkvFixtureWriter::unknownSizeElement(MkvFixtureWriter::TRACKS, "")))), ...$parsing],
            "CueEditing.php: slice start after end"         => [fn () => self::subtitle()->slice(5, 1), ...$invalid],
            "CueEditing.php: split time outside the cue"    => [fn () => self::subtitle()->splitCue(0, 9, 1), ...$invalid],
            "CueEditing.php: split line out of range"       => [fn () => self::subtitle()->splitCue(0, 1.5, 5), ...$invalid],
            "CueEditing.php: join in the wrong order"       => [fn () => self::subtitle()->joinCues(1, 0), ...$invalid],
            "CueEditing.php: edit a missing cue"            => [fn () => self::subtitle()->splitCue(9, 1.5, 1), ...$invalid],
            "CueLookup.php: range start after end"          => [fn () => self::subtitle()->getCuesBetween(10, 5), ...$invalid],
            "Diff/SubtitleDiffOptions.php: negative tolerance" => [fn () => new SubtitleDiffOptions(-1), ...$invalid],
            "DualSubtitleOptions.php: unknown mode"         => [fn () => new DualSubtitleOptions("side"), ...$invalid],
            "DualSubtitleOptions.php: negative snap"        => [fn () => new DualSubtitleOptions(snapTolerance: -1), ...$invalid],
            "DualSubtitleOptions.php: unknown style"        => [fn () => new DualSubtitleOptions(secondaryStyle: "blink"), ...$invalid],
            "DualSubtitleOptions.php: alignment 0"          => [fn () => new DualSubtitleOptions(secondaryAlignment: 0), ...$invalid],
            "Encoding/Cea608.php: row 16"                   => [fn () => Cea608::encodePac(16, 0), ...$invalid],
            "Fixes.php: minimum duration 0"                 => [fn () => self::subtitle()->extendShortCues(0), ...$invalid],
            "Fixes.php: maximum characters 0"               => [fn () => self::subtitle()->wrapLines(0), ...$invalid],
            "Fixes.php: negative gap"                       => [fn () => self::subtitle()->fixOverlaps(-1), ...$invalid],
            "Fixing/CommonErrorOptions.php: dialogue dash"  => [fn () => new CommonErrorOptions(dialogueDash: "*"), ...$invalid],
            "Fixing/OcrReplaceList.php: invalid regex"      => [fn () => new OcrReplaceList(regularExpressions: ["/(/" => ""]), ...$invalid],
            "Fixing/OcrReplaceList.php: invalid XML"        => [fn () => OcrReplaceList::fromSubtitleEditXml("<ReplaceList>"), ...$parsing],
            "Formatters/JsonOutput.php: invalid UTF-8" => [fn () => Subtitle::load(self::FILES . "cli/latin1.srt", Format::SubRip)->toString(Format::Json),
                ...$invalid],
            "Formatters/CsvFormatter.php: frames without rate" => [fn () => self::subtitle()->toString(Format::Csv,
                new WriteOptions(format: new CsvWriteOptions(timeFormat: CsvTimeFormat::Frames))), ...$invalid],
            "Formatters/EbuStlFormatter.php: code table 09" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["gsi" => ["CCT" => "09"]])->toString(Format::EbuStl), ...$invalid],
            "Formatters/EbuStlFormatter.php: subtitle number 65536" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["firstSubtitleNumber" => 65536])->toString(Format::EbuStl), ...$invalid],
            "Formatters/EbuStlFormatter.php: text too long" => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 30000)))
                ->toString(Format::EbuStl), ...$invalid],
            "Formatters/IttFormatter.php: no frame rate"    => [fn () => (new IttFormatter())->format(self::subtitle(), new WriteOptions()), ...$invalid],
            "Formatters/MicroDvdFormatter.php: no frame rate" => [fn () => (new MicroDvdFormatter())->format(self::subtitle(), new WriteOptions()),
                                                                ...$invalid],
            "Formatters/Options/CsvWriteOptions.php: frame rate 0" => [fn () => new CsvWriteOptions(frameRate: 0), ...$invalid],
            "Formatters/Options/EbuStlWriteOptions.php: frame rate 24" => [fn () => new EbuStlWriteOptions(frameRate: 24), ...$invalid],
            "Formatters/Options/HtmlTranscriptWriteOptions.php: negative paragraph gap" => [fn () => new HtmlTranscriptWriteOptions(paragraphGap: -1),
                ...$invalid],
            "Formatters/Options/IttWriteOptions.php: frame rate 50" => [fn () => new IttWriteOptions(frameRate: 50), ...$invalid],
            "Formatters/Options/MicroDvdWriteOptions.php: frame rate 0" => [fn () => new MicroDvdWriteOptions(frameRate: 0), ...$invalid],
            "Formatters/Options/MpSubWriteOptions.php: frame rate 0" => [fn () => new MpSubWriteOptions(frameRate: 0), ...$invalid],
            "Formatters/Options/PlainTextWriteOptions.php: negative paragraph gap" => [fn () => new PlainTextWriteOptions(paragraphGap: -1), ...$invalid],
            "Formatters/PgsFormatter.php: text cue"         => [fn () => self::subtitle()->toString(Format::Pgs), ...$invalid],
            "Formatters/PgsFormatter.php: negative x"       => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), -1, 0, 1, 1, 9, 9))
                ->toCue(new SubtitleCue(1, 2)))->toString(Format::Pgs), ...$invalid],
            "Formatters/PgsFormatter.php: PNG size"         => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), 0, 0, 2, 1, 9, 9))
                ->toCue(new SubtitleCue(1, 2)))->toString(Format::Pgs), ...$invalid],
            "Formatters/PgsFormatter.php: negative time"    => [fn () => (new Subtitle())->addCue((new CueImage(self::png(), 0, 0, 1, 1, 9, 9))
                ->toCue(new SubtitleCue(-1, 2)))->toString(Format::Pgs), ...$invalid],
            "Formatters/SccFormatter.php: 5 lines"          => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, ["1", "2", "3", "4", "5"]))
                ->toString(Format::Scc), ...$invalid],
            "Formatters/SccFormatter.php: 33 characters"    => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 33)))
                ->toString(Format::Scc), ...$invalid],
            "Formatters/SccFormatter.php: no CEA-608 character" => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, "\u{20AC}"))
                ->toString(Format::Scc), ...$invalid],
            "Formatters/SubtitleFormatter.php: options of another format" => [fn () => self::subtitle()->toString(Format::SubRip,
                new WriteOptions(format: new CsvWriteOptions())), ...$invalid],
            "Formatters/TtmlFormatter.php: stored head"     => [fn () => self::subtitle()->setFormatData(TtmlParser::FORMAT_DATA_KEY, ["head" => "<p/>"])
                ->toString(Format::Ttml), ...$invalid],
            "FrameRate.php: frame rate 0"                   => [fn () => new FrameRate(0), ...$invalid],
            "FormatDataSchema.php: wrong type"              => [fn () => self::fromArray(["formatData" => ["scc" => ["dropFrame" => "x"]]]), ...$parsing],
            "FormatDataSchema.php: numeric key"             => [fn () => self::fromArray(["formatData" => ["ttml" => ["body" => ["x"]]]]), ...$parsing],
            "FormatDataSchema.php: unknown key"             => [fn () => self::fromArray(["formatData" => ["csv" => ["header" => null, "width" => 1, "roles" => ["x" => 0]]]]), ...$parsing],
            "FormatDataSchema.php: missing field"           => [fn () => self::fromArray(["formatData" => ["csv" => ["delimiter" => ","]]]), ...$parsing],
            "HearingImpairedOptions.php: empty bracket"     => [fn () => new HearingImpairedOptions(customBrackets: [["{", ""]]), ...$invalid],
            "Hls/HlsSegmentOptions.php: segment duration 0" => [fn () => new HlsSegmentOptions(segmentDuration: 0), ...$invalid],
            "Hls/HlsSegmentOptions.php: no %d in pattern"   => [fn () => new HlsSegmentOptions(fileNamePattern: "sub.vtt"), ...$invalid],
            "Hls/HlsSegmentOptions.php: media duration 0"   => [fn () => new HlsSegmentOptions(mediaDuration: 0), ...$invalid],
            "Hls/HlsWebVttSegmenter.php: no duration"       => [fn () => HlsWebVttSegmenter::segment(new Subtitle()), ...$invalid],
            "Hls/TimestampMap.php: MPEGTS above 33 bits"    => [fn () => new TimestampMap(TimestampMap::MPEGTS_WRAP), ...$invalid],
            "Hls/TimestampMap.php: negative LOCAL"          => [fn () => new TimestampMap(0, -1), ...$invalid],
            "Hls/TimestampMap.php: other header"            => [fn () => TimestampMap::fromHeader("WEBVTT"), ...$parsing],
            "Hls/TimestampMap.php: no MPEGTS"               => [fn () => TimestampMap::fromHeader("X-TIMESTAMP-MAP=LOCAL:00:00.000"), ...$parsing],
            "Image/CueImage.php: width 0"                   => [fn () => new CueImage("png", 0, 0, 0, 1, 1, 1), ...$invalid],
            "Image/CueImage.php: too large"                 => [fn () => new CueImage("png", 0, 0, 8000, 1, 1, 1), ...$invalid],
            "Image/CueImage.php: no image"                  => [fn () => CueImage::fromCue(new SubtitleCue(1, 2, "text")), ...$invalid],
            "Image/CueImage.php: no integer x"              => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["png" => "png"])), ...$invalid],
            "Image/CueImage.php: no png"                    => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["x" => 0, "y" => 0, "width" => 1, "height" => 1,
                                                            "screenWidth" => 1, "screenHeight" => 1])), ...$invalid],
            "Image/PngEncoder.php: width 0"                 => [fn () => PngEncoder::encode(0, 1, []), ...$invalid],
            "Image/PngEncoder.php: pixel count"             => [fn () => PngEncoder::encode(1, 1, []), ...$invalid],
            "Image/PaletteReducer.php: 257 colors"          => [fn () => PaletteReducer::reduce([0], 257), ...$invalid],
            "Image/PngDecoder.php: no signature"            => [fn () => PngDecoder::decode("GIF89a"), ...$invalid],
            "Image/PngDecoder.php: cut off chunk"           => [fn () => PngDecoder::decode(substr(self::png(), 0, 20)), ...$invalid],
            "Image/PngDecoder.php: no IHDR"                 => [fn () => PngDecoder::decode("\x89PNG\r\n\x1a\n"), ...$invalid],
            "Image/PngDecoder.php: interlaced"              => [fn () => PngDecoder::decode(self::pngWithIhdr(1) . self::pngChunk("IDAT", "")), ...$invalid],
            "Image/PngDecoder.php: too large"               => [fn () => PngDecoder::decode("\x89PNG\r\n\x1a\n" .
                self::pngChunk("IHDR", pack("NNCCCCC", 8000, 1, 8, 6, 0, 0, 0)) . self::pngChunk("IDAT", "")), ...$invalid],
            "Image/PngDecoder.php: invalid zlib data"       => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", "nope")), ...$invalid],
            "Image/PngDecoder.php: too few rows"            => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", gzcompress(""))),
                                                                ...$invalid],
            "Image/PngDecoder.php: filter type 5"           => [fn () => PngDecoder::decode(self::pngWithIhdr(0) . self::pngChunk("IDAT", gzcompress("\5\0\0\0\0"))),
                                                                ...$invalid],
            "Image/PngDecoder.php: zlib missing"            => [fn () => (new \ReflectionMethod(PngDecoder::class, "requireFunction"))
                ->invoke(null, "gzuncompress_missing"), ...$invalid],
            "Karaoke/WordHighlightOptions.php: speaker style" => [fn () => new WordHighlightOptions(style: "v Ann"), ...$invalid],
            "Karaoke/WordHighlightOptions.php: unknown mode"  => [fn () => new WordHighlightOptions(mode: "line"), ...$invalid],
            "Karaoke/WordHighlightOptions.php: 0 words"       => [fn () => new WordHighlightOptions(maxWordsPerCue: 0), ...$invalid],
            "MergeShortCuesOptions.php: maximum lines 0"    => [fn () => new MergeShortCuesOptions(maxLines: 0), ...$invalid],
            "MergeShortCuesOptions.php: negative gap"       => [fn () => new MergeShortCuesOptions(maxGap: -1), ...$invalid],
            "MergeShortCuesOptions.php: maximum duration 0" => [fn () => new MergeShortCuesOptions(maxDuration: 0), ...$invalid],
            "MergeShortCuesOptions.php: minimum characters 0" => [fn () => new MergeShortCuesOptions(minCharacters: 0), ...$invalid],
            "Ocr/GlyphOcrEngine.php: unknown option"        => [fn () => new GlyphOcrEngine(null, ["speed" => 2]), ...$invalid],
            "Ocr/GlyphOcrEngine.php: invalid option"        => [fn () => new GlyphOcrEngine(null, ["inkThreshold" => 0]), ...$invalid],
            "Ocr/GlyphOcrEngine.php: no PNG"                => [fn () => (new GlyphOcrEngine())
                ->recognize(new CueImage("png", 0, 0, 1, 1, 1, 1), null), ...$invalid],
            "Ocr/GlyphOcrEngine.php: package missing"       => [fn () => (new \ReflectionMethod(GlyphOcrEngine::class, "requireClass"))
                ->invoke(null, "GlyphOcr\\Missing"), ...$invalid],
            "Ocr/OcrEngineChooser.php: unknown engine"      => [fn () => OcrEngineChooser::choose("easyocr"), ...$invalid],
            "Ocr/OcrResult.php: line is no string"          => [fn () => new OcrResult([5]), ...$invalid],
            "Ocr/OcrResult.php: confidence above 1"         => [fn () => new OcrResult(["text"], 2), ...$invalid],
            "Ocr/TesseractOcrEngine.php: mode 14"           => [fn () => new TesseractOcrEngine(pageSegmentationMode: 14), ...$invalid],
            "Ocr/TesseractOcrEngine.php: scale 0.5"         => [fn () => new TesseractOcrEngine(scale: 0.5), ...$invalid],
            "Ocr/TesseractOcrEngine.php: threshold 0"       => [fn () => new TesseractOcrEngine(threshold: 0), ...$invalid],
            "Ocr/TesseractOcrEngine.php: program missing"   => [fn () => (new TesseractOcrEngine(program: __DIR__ . "/none"))
                ->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), null), ...$invalid],
            "Ocr/TesseractOcrEngine.php: language missing"  => [fn () => (new TesseractOcrEngine(program: self::FAKE_TESSERACT))
                ->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), "xyz"), ...$invalid],
            "Ocr/TesseractOcrEngine.php: program fails"     => [function (): void {
                putenv("FAKE_TESSERACT_FAIL=1");
                try {
                    (new TesseractOcrEngine(program: self::FAKE_TESSERACT))->recognize(new CueImage(self::png(), 0, 0, 1, 1, 1, 1), null);
                } finally {
                    putenv("FAKE_TESSERACT_FAIL");
                }
            }, ...$invalid],
            "Parsers/AssParser.php: no events section"      => [fn () => (new AssParser())->parse("[Script Info]\nTitle: x\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: too few fields"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Start, End, Text\n" .
                                                                                                  "Dialogue: 0,0:00:01.00\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: no Start field"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Text\n" .
                                                                                                  "Dialogue: 0,text\n", new ReadOptions()), ...$parsing],
            "Parsers/AssParser.php: invalid time"           => [fn () => (new AssParser())->parse("[Events]\nFormat: Start, End, Text\n" .
                                                                                                  "Dialogue: soon,0:00:02.00,text\n", new ReadOptions()), ...$parsing],
            "Parsers/AssemblyAiParser.php: no words"        => [fn () => (new AssemblyAiParser())->parse('{"text": "Hi"}', new ReadOptions()), ...$parsing],
            "Parsers/AwsTranscribeParser.php: no items"     => [fn () => (new AwsTranscribeParser())->parse('{"results": {}}', new ReadOptions()), ...$parsing],
            "Parsers/Options/CsvColumns.php: negative index"        => [fn () => new CsvColumns(start: -1), ...$invalid],
            "Parsers/Options/CsvColumns.php: name without header"   => [fn () => new CsvColumns(start: 0, text: "Text", header: false), ...$invalid],
            "Parsers/CsvParser.php: delimiter"              => [fn () => new CsvReadOptions(delimiter: "|"), ...$invalid],
            "Parsers/CsvParser.php: open quote"             => [fn () => (new CsvParser())->parse("start,text\n1,\"a", new ReadOptions()), ...$parsing],
            "Parsers/CsvParser.php: bad time"               => [fn () => (new CsvParser())->parse("start,text\nsoon,a", new ReadOptions()), ...$parsing],
            "Parsers/CsvParser.php: missing column"         => [fn () => (new CsvParser())->parse("start,end\n1,2", new ReadOptions()), ...$parsing],
            "Parsers/DeepgramParser.php: no channels"       => [fn () => (new DeepgramParser())->parse('{"metadata": {}}', new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: no GSI block"        => [fn () => (new EbuStlParser())->parse("STL", new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: partial TTI block"   => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1025), new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: disk format code"    => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1024), new ReadOptions()), ...$parsing],
            "Parsers/EbuStlParser.php: code table 09"       => [fn () => (new EbuStlParser())->parse(
                str_pad("850STL25.01109", 1024, " "), new ReadOptions()), ...$parsing],
            "Parsers/GoogleSpeechParser.php: no results"    => [fn () => (new GoogleSpeechParser())->parse('{"done": true}', new ReadOptions()), ...$parsing],
            "Parsers/GoogleSpeechParser.php: alternatives"  => [fn () => (new GoogleSpeechParser())->parse('{"results": [{"alternatives": "x"}]}', new ReadOptions()), ...$parsing],
            "Parsers/FfMetadataChaptersParser.php: no header" => [fn () => (new FfMetadataChaptersParser())->parse("title=x", new ReadOptions()), ...$parsing],
            "Parsers/FfMetadataChaptersParser.php: time base 0" => [fn () => (new FfMetadataChaptersParser())->parse(
                ";FFMETADATA1\n[CHAPTER]\nTIMEBASE=0/1\n", new ReadOptions()), ...$parsing],
            "Parsers/HtmlTranscriptParser.php: no time"     => [fn () => (new HtmlTranscriptParser())->parse("<p>Hi</p>", new ReadOptions()), ...$parsing],
            "Parsers/HtmlTranscriptParser.php: bad time"    => [fn () => (new HtmlTranscriptParser())->parse("<time>x</time><p>Hi</p>", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/JsonParser.php: no JSON"               => [fn () => (new JsonParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/JsonParser.php: root no object"        => [fn () => (new JsonParser())->parse("[1]", new ReadOptions()), ...$parsing],
            "Parsers/JsonParser.php: invalid base64"        => [fn () => (new JsonParser())->parse(
                '{"version": 1, "cues": [], "formatData": {"stl": {"base64": "!"}}}', new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: no frame rate"     => [fn () => (new MicroDvdParser())->parse("{0}{25}text", new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: frame rate 0"      => [fn () => (new MicroDvdParser())->parse("{1}{1}0\n{0}{25}text", new ReadOptions()), ...$parsing],
            "Parsers/MicroDvdParser.php: no cue"            => [fn () => (new MicroDvdParser())->parse("text", new ReadOptions(format: new MicroDvdReadOptions(25))), ...$parsing],
            "Parsers/MpSubParser.php: no timing line"       => [fn () => (new MpSubParser())->parse("FORMAT=TIME\ntext\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: negative duration"    => [fn () => (new MpSubParser())->parse("FORMAT=TIME\n0 -1\ntext\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: no text lines"        => [fn () => (new MpSubParser())->parse("FORMAT=TIME\n0 1\n\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: unknown FORMAT"       => [fn () => (new MpSubParser())->parse("FORMAT=FAST\n", new ReadOptions()), ...$parsing],
            "Parsers/MpSubParser.php: frame rate 0"         => [fn () => (new MpSubParser())->parse("FORMAT=0\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: no name line"   => [fn () => (new OgmChaptersParser())->parse(
                "CHAPTER01=00:00:00.000\nCHAPTER02=00:00:01.000\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: no time line"   => [fn () => (new OgmChaptersParser())->parse("CHAPTER01NAME=x\n", new ReadOptions()), ...$parsing],
            "Parsers/OgmChaptersParser.php: second 60"      => [fn () => (new OgmChaptersParser())->parse("CHAPTER01=00:00:60.000\n", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: no magic bytes"         => [fn () => (new PgsParser())->parse("XG", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off header"         => [fn () => (new PgsParser())->parse("PG\0\0", new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off data"           => [fn () => (new PgsParser())->parse(substr(self::pgsSegment(0x14, "\0\0"), 0, -1), new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PgsParser.php: cut off presentation"   => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16, "\0\0"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off object"         => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off cropping"       => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\0\0\x80\0\0\0\0"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: cut off definition"     => [fn () => (new PgsParser())->parse(self::pgsSegment(0x15, "\0\1\0\x80"), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: short bitmap"           => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x16, "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\7\0\0\0\0\0\0") .
                self::pgsSegment(0x14, "\0\0\1\x10\x80\x80\xFF") .
                self::pgsSegment(0x15, "\0\7\0\xC0\0\0\7\0\4\0\2\1\1\0\0") .
                self::pgsSegment(0x80, ""), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: object too large"       => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x15, "\0\7\0\xC0\0\0\4" . pack("nn", 8000, 1)), new ReadOptions()), ...$parsing],
            "Parsers/PgsParser.php: objects too far apart"  => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x16, "\x02\xD0\x02\x40\x10\0\1\x80\0\0\2" . "\0\1\0\0\0\0\0\0" . "\0\2\0\0" . pack("nn", 8000, 0)) .
                self::pgsSegment(0x14, "\0\0\1\x10\x80\x80\xFF") .
                self::pgsSegment(0x15, "\0\1\0\xC0\0\0\5\0\1\0\1\1") .
                self::pgsSegment(0x15, "\0\2\0\xC0\0\0\5\0\1\0\1\1") .
                self::pgsSegment(0x80, ""), new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: no JSON"    => [fn () => (new PodcastChaptersParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: no chapters" => [fn () => (new PodcastChaptersParser())->parse('{"version": "1.2.0"}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: start no number" => [fn () => (new PodcastChaptersParser())->parse(
                '{"chapters": [{"title": "x"}]}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastChaptersParser.php: end no number" => [fn () => (new PodcastChaptersParser())->parse(
                '{"chapters": [{"startTime": 1, "endTime": "2"}]}', new ReadOptions()), ...$parsing],
            "Parsers/PodcastTranscriptParser.php: no JSON"  => [fn () => (new PodcastTranscriptParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/PodcastTranscriptParser.php: root no object" => [fn () => (new PodcastTranscriptParser())->parse("[1]", new ReadOptions()), ...$parsing],
            "Parsers/PodcastTranscriptParser.php: no segments" => [fn () => (new PodcastTranscriptParser())->parse('{"version": "1.0.0"}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PodcastTranscriptParser.php: segment no object" => [fn () => (new PodcastTranscriptParser())->parse('{"segments": [1]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/PodcastTranscriptParser.php: time no number" => [fn () => (new PodcastTranscriptParser())->parse(
                '{"segments": [{"startTime": "0"}]}', new ReadOptions()), ...$parsing],
            "Parsers/SamiParser.php: invalid UTF-8"         => [fn () => (new SamiParser())->parse("<SAMI>\xFF</SAMI>", new ReadOptions()), ...$parsing],
            "Parsers/SamiParser.php: no Start attribute"    => [fn () => (new SamiParser())->parse("<SAMI><BODY><SYNC>text</BODY></SAMI>", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SamiParser.php: unknown class"         => [fn () => (new SamiParser())->parse(
                "<SAMI><BODY><SYNC Start=0><P Class=ENCC>text</BODY></SAMI>", new ReadOptions(format: new SamiReadOptions("FRCC"))), ...$parsing],
            "Parsers/Options/SamiReadOptions.php: empty language"   => [fn () => new SamiReadOptions(" "), ...$invalid],
            "Parsers/SbvParser.php: no timestamps"          => [fn () => (new SbvParser())->parse("text\nmore", new ReadOptions()), ...$parsing],
            "Parsers/SbvParser.php: no text lines"          => [fn () => (new SbvParser())->parse("0:00:01.000,0:00:02.000", new ReadOptions()), ...$parsing],
            "Parsers/SbvParser.php: invalid time"           => [fn () => (new SbvParser())->parse("soon,0:00:02.000\ntext", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: other header"           => [fn () => (new SccParser())->parse("Scenarist_SCC V2.0\n", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: no time code"           => [fn () => (new SccParser())->parse(SccParser::HEADER . "\n\n942c\n", new ReadOptions()), ...$parsing],
            "Parsers/SccParser.php: invalid byte pair"      => [fn () => (new SccParser())->parse(SccParser::HEADER . "\n\n00:00:01:00\t94zz\n", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SccParser.php: empty file"             => [fn () => (new SccParser())->parse("", new ReadOptions()), ...$parsing],
            "Parsers/SubRipParser.php: no cue number"       => [fn () => (new SubRipParser())->parse("x\n00:00:01,000 --> 00:00:02,000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: no timestamps"       => [fn () => (new SubRipParser())->parse("1\ntext\nmore", new ReadOptions()), ...$parsing],
            "Parsers/Options/SccReadOptions.php: channel 3"         => [fn () => new SccReadOptions(3), ...$invalid],
            "Parsers/SubRipParser.php: no text lines"       => [fn () => (new SubRipParser())->parse("1\n00:00:01,000 --> 00:00:02,000", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: invalid time"        => [fn () => (new SubRipParser())->parse("1\nsoon --> 00:00:02,000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/SubViewerParser.php: version 1 header" => [fn () => (new SubViewerParser())->parse("text\n" . SubViewerParser::START_SCRIPT . "\n", new ReadOptions()), ...$parsing],
            "Parsers/SubViewerParser.php: version 2 header" => [fn () => (new SubViewerParser())->parse("text\n", new ReadOptions()), ...$parsing],
            "Parsers/SubtitleParser.php: options of another format" => [fn () => (new SubRipParser())->parse("", new ReadOptions(format: new CsvReadOptions())),
                                                                ...$invalid],
            "Parsers/TtmlParser.php: no tt root"            => [fn () => (new TtmlParser())->parse("<html/>", new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: invalid time"          => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="soon" end="2s">text</p>'), new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: empty file"            => [fn () => (new TtmlParser())->parse("", new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: not well-formed"       => [fn () => (new TtmlParser())->parse("<tt", new ReadOptions()), ...$parsing],
            "Parsers/TtmlParser.php: no end time"           => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="1s">text</p>'), new ReadOptions()), ...$parsing],
            "Parsers/VobSubParser.php: no VobSubReadOptions" => [fn () => (new VobSubParser())->parse("", new ReadOptions()), ...$invalid],
            "Parsers/VobSubParser.php: no track"            => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX))), ...$parsing],
            "Parsers/VobSubParser.php: filepos outside"     => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: no header line"      => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions("size: 720x576\n"))), ...$parsing],
            "Parsers/VobSubParser.php: invalid size"        => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "size: 0x0\n"))), ...$parsing],
            "Parsers/VobSubParser.php: invalid custom colors" => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "custom colors: yes\n"))), ...$parsing],
            "Parsers/VobSubParser.php: invalid id"          => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "id: en, index: 99\n"))), ...$parsing],
            "Parsers/VobSubParser.php: timestamp before id" => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "timestamp: 00:00:01:000, filepos: 0\n"))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: invalid timestamp"   => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "id: en, index: 0\ntimestamp: soon\n"))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no size"             => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions("# VobSub index file\nid: en, index: 0\n"))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no palette"          => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions("# VobSub index file\nsize: 720x576\n"))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: short palette"       => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "palette: 000000\n"))), ...$parsing],
            "Parsers/VobSubParser.php: invalid time"        => [fn () => (new VobSubParser())->parse("", new ReadOptions(format: new VobSubReadOptions(self::IDX . "delay: soon\n"))), ...$parsing],
            "Parsers/VobSubParser.php: no start code"       => [fn () => (new VobSubParser())->parse("text", new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: cut off pack header" => [fn () => (new VobSubParser())->parse("\0\0\1\xBA\x44\0", new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: program end code"    => [fn () => (new VobSubParser())->parse("\0\0\1\xB9\0\0", new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: packet too long"     => [fn () => (new VobSubParser())->parse(
                substr(self::vobSubPacket("\0\4\0\4"), 0, -1), new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: no control sequence" => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\2"), new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: cut off command"     => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x09\0\4\0\0\0\4\x05"), new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: image too large"     => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x15\0\4\0\0\0\4\x05\x00\x0F\xFF\x00\x0F\xFF\x06\0\0\0\0\xFF"),
                new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/VobSubParser.php: cut off bitmap"      => [fn () => (new VobSubParser())->parse(
                self::vobSubPacket("\0\x15\0\4\0\0\0\4\x05\0\0\1\0\0\1\x06\0\x15\0\x15\xFF"), new ReadOptions(format: new VobSubReadOptions(self::IDX_WITH_TRACK))), ...$parsing],
            "Parsers/Options/VobSubReadOptions.php: empty language" => [fn () => new VobSubReadOptions(language: " "), ...$invalid],
            "Parsers/Options/VobSubReadOptions.php: negative track" => [fn () => new VobSubReadOptions(track: -1), ...$invalid],
            "Parsers/WebVttParser.php: no WEBVTT"           => [fn () => (new WebVttParser())->parse("text", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: unknown block"       => [fn () => (new WebVttParser())->parse("WEBVTT\n\ntext\nmore", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: no empty header line" => [fn () => (new WebVttParser())->parse(
                "WEBVTT\n00:00:01.000 --> 00:00:02.000\ntext", new ReadOptions()), ...$parsing],
            "Parsers/WebVttParser.php: no text lines"       => [fn () => (new WebVttParser())->parse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WebVttParser.php: invalid end time"    => [fn () => (new WebVttParser())->parse("WEBVTT\n\n00:00:01.000 --> soon\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WebVttParser.php: invalid start time"  => [fn () => (new WebVttParser())->parse("WEBVTT\n\nsoon --> 00:00:02.000\ntext", new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WhisperJsonParser.php: no JSON"        => [fn () => (new WhisperJsonParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/WhisperJsonParser.php: root no object" => [fn () => (new WhisperJsonParser())->parse("[1]", new ReadOptions()), ...$parsing],
            "Parsers/WhisperJsonParser.php: no segments"    => [fn () => (new WhisperJsonParser())->parse('{"text": "Hi"}', new ReadOptions()), ...$parsing],
            "Parsers/WhisperJsonParser.php: time no number" => [fn () => (new WhisperJsonParser())->parse('{"segments": [{"start": "0"}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WhisperJsonParser.php: text no string" => [fn () => (new WhisperJsonParser())->parse(
                '{"segments": [{"start": 0, "end": 1}]}', new ReadOptions()), ...$parsing],
            "Parsers/WordGrouping.php: no JSON"             => [fn () => (new DeepgramParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/WordGrouping.php: root no object"      => [fn () => (new GoogleSpeechParser())->parse("[1]", new ReadOptions()), ...$parsing],
            "Parsers/WordGrouping.php: bad time"            => [fn () => (new AssemblyAiParser())->parse('{"words": [{"text": "Hi", "start": "soon"}]}', new ReadOptions()),
                                                                ...$parsing],
            "Parsers/WordGrouping.php: text no string"      => [fn () => (new AwsTranscribeParser())->parse(
                '{"results": {"items": [{"alternatives": []}]}}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: no JSON"   => [fn () => (new YouTubeTimedTextParser())->parse("{", new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: no events" => [fn () => (new YouTubeTimedTextParser())->parse('{"segs": []}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: time no number" => [fn () => (new YouTubeTimedTextParser())->parse(
                '{"events": [{"tStartMs": "0"}]}', new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: no XML"    => [fn () => (new YouTubeTimedTextParser())->parse("<transcript>", new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: other root" => [fn () => (new YouTubeTimedTextParser())->parse("<tt/>", new ReadOptions()), ...$parsing],
            "Parsers/YouTubeTimedTextParser.php: bad time"  => [fn () => (new YouTubeTimedTextParser())->parse(
                '<transcript><text dur="1">Hi</text></transcript>', new ReadOptions()), ...$parsing],
            "Profanity/ProfanityOptions.php: star in a word" => [fn () => new ProfanityOptions(["f*ck"]), ...$invalid],
            "Profanity/ProfanityOptions.php: no words"      => [fn () => new ProfanityOptions(), ...$invalid],
            "Profanity/ProfanityOptions.php: unknown mask"  => [fn () => new ProfanityOptions(["hell"], "blur"), ...$invalid],
            "Profanity/ProfanityOptions.php: negative padding" => [fn () => new ProfanityOptions(["hell"], padding: -1), ...$invalid],
            "Profanity/ProfanityOptions.php: missing word file" => [fn () => new ProfanityOptions(wordFile: __DIR__ . "/missing.txt"), ...$invalid],
            "ResegmentOptions.php: maximum lines 0"         => [fn () => new ResegmentOptions(ResegmentMode::SplitLong, maxLines: 0), ...$invalid],
            "ResegmentOptions.php: negative word gap"       => [fn () => new ResegmentOptions(ResegmentMode::SplitLong, maxWordGap: -1), ...$invalid],
            "ResegmentOptions.php: maximum duration 0"      => [fn () => new ResegmentOptions(ResegmentMode::SplitLong, maxDuration: 0), ...$invalid],
            "ReadOptions.php: unknown encoding"             => [fn () => new ReadOptions(encoding: "NO-SUCH-ENCODING"), ...$invalid],
            "ReadOptions.php: negative last cue duration"   => [fn () => new ReadOptions(lastCueDuration: -1), ...$invalid],
            "Retiming.php: scale factor 0"                  => [fn () => self::subtitle()->scale(0), ...$invalid],
            "Retiming.php: same old times"                  => [fn () => self::subtitle()->syncByTwoPoints(1, 1, 1, 2), ...$invalid],
            "Retiming.php: new times in reverse"            => [fn () => self::subtitle()->syncByTwoPoints(1, 2, 2, 1), ...$invalid],
            "Speakers/SpeakerLabelOptions.php: from a style other than prefix" => [fn () => new SpeakerLabelOptions(from: SpeakerStyle::Colours),
                                                                ...$invalid],
            "Speakers/SpeakerLabelOptions.php: invalid colour" => [fn () => new SpeakerLabelOptions(colours: ["yellow"]), ...$invalid],
            "Streaming/Streams.php: no stream"              => [fn () => iterator_to_array((new SubRipStreamReader())->read(5)), ...$invalid],
            "Streaming/Streams.php: missing file"           => [fn () => iterator_to_array((new SubRipStreamReader())->read(__DIR__ . "/missing.srt")),
                                                                ...$invalid],
            "Streaming/Streams.php: closed writer"          => [fn () => self::closedWriter()->write(new SubtitleCue(1, 2, "text")), ...$invalid],
            "Streaming/Streams.php: read-only stream"       => [fn () => new SubRipStreamWriter(fopen("php://memory", "rb")), ...$invalid],
            "Streaming/WebVttStreamReader.php: no WEBVTT"   => [fn () => iterator_to_array((new WebVttStreamReader())->read(self::stream("text"))),
                                                                ...$parsing],
            "Streaming/WebVttStreamReader.php: unknown block" => [fn () => iterator_to_array((new WebVttStreamReader())->read(
                self::stream("WEBVTT\n\ntext\nmore"))), ...$parsing],
            "StringHelpers.php: unknown encoding"           => [fn () => StringHelpers::convertToUtf8("text", "NO-SUCH-ENCODING"),
                                                                ...$parsing],
            "Subtitle.php: unknown format"                  => [fn () => Subtitle::fromStringAutoDetectFormat("text"),
                                                                InvalidParserException::class, UnknownFormatException::class],
            "Subtitle.php: unknown format of a file"        => [fn () => Subtitle::loadAutoDetectFormat(self::FILES . "chapters/ffmetadata/real/m4b_audiobook.ffmeta"),
                                                                InvalidParserException::class, UnknownFormatException::class],
            "Subtitle.php: load() of an MKV file"           => [fn () => Subtitle::load(self::FILES . "mkv/pgs.mkv", Format::Pgs),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: fromString() of MKV content"     => [fn () => Subtitle::fromString(MatroskaReader::EBML_MAGIC, Format::SubRip),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: MKV with 2 subtitle tracks"      => [fn () => Subtitle::loadAutoDetectFormat(self::FILES . "mkv/pgs.mkv"),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: VobSub without its .sub file"    => [fn () => Subtitle::load(self::FILES . "vobsub/SOURCES.md", Format::VobSub), ...$invalid],
            "Subtitle.php: missing file"                    => [fn () => Subtitle::load(self::FILES . "missing.srt", Format::SubRip), ...$invalid],
            "Subtitle.php: unreadable file"                 => [fn () => Subtitle::load(self::unreadableFile(), Format::SubRip), ...$invalid],
            "Subtitle.php: save() to an unknown extension"  => [fn () => self::subtitle()->save(self::FILES . "out.unknown"),
                                                                InvalidFormatterException::class, InvalidFormatterException::class],
            "Subtitle.php: save() into a missing directory" => [fn () => self::subtitle()->save(self::FILES . "missing/out.srt"), ...$invalid],
            "Subtitle.php: MicroDVD without a frame rate"   => [fn () => self::subtitle()->toString(Format::MicroDvd), ...$invalid],
            "Subtitle.php: iTT without a frame rate"        => [fn () => self::subtitle()->toString(Format::Itt), ...$invalid],
            "Subtitle.php: format without a parser"         => [fn () => Subtitle::fromString("text", Format::PlainText),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: format without a formatter"      => [fn () => self::subtitle()->toString(Format::Whisper),
                                                                InvalidFormatterException::class, InvalidFormatterException::class],
            "Subtitle.php: image cue without text"          => [fn () => (new Subtitle())->addCue($imageCue)->toString(Format::SubRip),
                                                                ImageCueWithoutTextException::class, ImageCueWithoutTextException::class],
            "Subtitle.php: remove a missing cue"            => [fn () => self::subtitle()->removeCue(9),
                                                                \RuntimeException::class, CueNotFoundException::class],
            "Subtitle.php: negative comment index"          => [fn () => self::subtitle()->addComment("note", -1), ...$invalid],
            "SubtitleCue.php: lines of the wrong type"      => [fn () => (new SubtitleCue())->setLines(5), ...$invalid],
            "SubtitleCue.php: alignment 10"                 => [fn () => (new SubtitleCue())->setAlignment(10), ...$invalid],
            "Sync/ReferenceSyncOptions.php: offset beyond a day" => [fn () => new ReferenceSyncOptions(new Subtitle(), maxOffset: 1e20), ...$invalid],
            "Sync/ReferenceSyncOptions.php: offset range too wide" => [fn () => new ReferenceSyncOptions(new Subtitle(), -5000, 5000), ...$invalid],
            "Sync/ReferenceSyncOptions.php: offsets in reverse" => [fn () => new ReferenceSyncOptions(new Subtitle(), 5, 1), ...$invalid],
            "Sync/ReferenceSyncOptions.php: negative split count" => [fn () => new ReferenceSyncOptions(new Subtitle(), maxSplits: -1), ...$invalid],
            "Sync/ReferenceSyncOptions.php: negative split penalty" => [fn () => new ReferenceSyncOptions(new Subtitle(), splitPenalty: -1), ...$invalid],
            "Sync/SpeechReference.php: media duration 0"    => [fn () => SpeechReference::fromFfmpegSilencedetect("", 0), ...$invalid],
            "Sync/SpeechReference.php: mono log"            => [fn () => SpeechReference::fromFfmpegSilencedetect("channel: 0 | silence_start: 1", 9),
                                                                ...$parsing],
            "Sync/SpeechReference.php: silence end first"   => [fn () => SpeechReference::fromFfmpegSilencedetect("silence_end: 1", 9),
                                                                ...$parsing],
            "Sync/SpeechReference.php: invalid interval"    => [fn () => SpeechReference::fromIntervals([[2, 1]]), ...$invalid],
            "TextTransforms.php: empty search"              => [fn () => self::subtitle()->replaceText("", "x"), ...$invalid],
            "TextTransforms.php: invalid regex"             => [fn () => self::subtitle()->replaceText("/[/", "x", true), ...$invalid],
            "TextTransforms.php: unknown case mode"         => [fn () => self::subtitle()->changeCase("title"), ...$invalid],
            "Timecode.php: drop frame at 25 fps"            => [fn () => Timecode::frameNumber(0, new FrameRate(25), true), ...$invalid],
            "Timing/ShotChangeOptions.php: frame rate 0"    => [fn () => new ShotChangeOptions(0), ...$invalid],
            "Timing/ShotChangeOptions.php: negative window" => [fn () => new ShotChangeOptions(24, snapWindow: -1), ...$invalid],
            "Timing/ShotChangeOptions.php: negative gap"    => [fn () => new ShotChangeOptions(24, minGapFrames: -1), ...$invalid],
            "Timing/ShotChangeOptions.php: negative minimum duration" => [fn () => new ShotChangeOptions(24, minDuration: -1),
                                                                ...$invalid],
            "Timing/ShotChanges.php: line without a time"   => [fn () => ShotChanges::fromText("abc"), ...$parsing],
            "Translation/TranslationOptions.php: cue limit 0" => [fn () => new TranslationOptions(maxCuesPerSentence: 0), ...$invalid],
            "Translation/TranslationOptions.php: character limit 0" => [fn () => new TranslationOptions(maxCharactersPerRequest: 0), ...$invalid],
            "Translation/TranslationRunner.php: no translations" => [fn () => (new TranslationRunner(new class implements TranslationEngine {
                public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
                {
                    return [];
                }
            }))->translate(self::subtitle(), "en", "de"), ...$invalid],
            "Validation/ValidationRules.php: dialogue dash style" => [fn () => new ValidationRules(dialogueDashStyle: "*"), ...$invalid],
            "Validation/ValidationRules.php: invalid character class" => [fn () => new ValidationRules(allowedCharacters: "[z-a]"),
                ...$invalid],
        ];
    }


    #[DataProvider("throwSites")]
    public function testOldCatchBlocksAndTheInterfaceCatchTheThrowSite(Closure $trigger, string $oldType, string $class): void
    {
        $caughtByOldType = null;
        try {
            $trigger();
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $caughtByOldType = $exception;
        }

        $caughtByInterface = null;
        try {
            $trigger();
        } catch (SubtitleToolboxException $exception) {
            $caughtByInterface = $exception;
        }

        $this->assertInstanceOf($oldType, $caughtByOldType);
        $this->assertInstanceOf(SubtitleToolboxException::class, $caughtByInterface);
        $this->assertSame($class, $caughtByInterface::class);
        $this->assertSame(self::CODES[$class], $caughtByInterface->getCode());
    }


    public function testEveryThrowSiteInSrcHasExactlyOneCase(): void
    {
        $cases      = [];
        $throwLines = [];
        foreach (self::throwSites() as $key => [$trigger]) {
            $file         = explode(":", $key)[0];
            $cases[$file] = ($cases[$file] ?? 0) + 1;
            try {
                $trigger();
            } catch (SubtitleToolboxException $exception) {
                $this->assertSame(realpath(self::SRC . $file), $exception->getFile(), $key);
                $throwLines[$exception->getFile() . ":" . $exception->getLine()] = $key;
            }
        }
        $this->assertCount(array_sum($cases), $throwLines);

        $sites = [];
        foreach ($this->sourceFiles() as $file => $code) {
            $count = preg_match_all('/\bthrow\s+new\b/', $code);
            if ($count > 0) {
                $sites[$file] = $count;
            }
        }
        ksort($cases);
        ksort($sites);

        $this->assertSame($sites, $cases);
    }


    public function testNoThrowSiteInSrcThrowsAGlobalClass(): void
    {
        $globalThrows = [];
        foreach ($this->sourceFiles() as $file => $code) {
            preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $imports, PREG_SET_ORDER);
            $aliases = [];
            foreach ($imports as $import) {
                $aliases[$import[2] ?? substr(strrchr("\\" . $import[1], "\\"), 1)] = $import[1];
            }
            preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $namespace);

            preg_match_all('/\bthrow\s+new\s+(\\\\?[\w\\\\]+)/', $code, $throws);
            foreach ($throws[1] as $name) {
                $class = match (true) {
                    str_starts_with($name, "\\") => substr($name, 1),
                    isset($aliases[$name])       => $aliases[$name],
                    default                      => $namespace[1] . "\\" . $name,
                };
                if (!is_subclass_of($class, SubtitleToolboxException::class)) {
                    $globalThrows[] = "$file: $name";
                }
            }
        }

        $this->assertSame([], $globalThrows);
    }


    public function testErrorCodesStartAt100WithoutGapsOrClashes(): void
    {
        $codes = [];
        foreach (glob(self::SRC . "Exceptions/*.php") as $path) {
            $class = __NAMESPACE__ . "\\" . basename($path, ".php");
            if (!(new \ReflectionClass($class))->isInstantiable()) {
                continue;
            }
            $codes[$class] = (new $class("message"))->getCode();
        }
        asort($codes);

        $this->assertSame(self::CODES, $codes);
        $this->assertSame(range(100, 99 + count($codes)), array_values($codes));
    }


    /**
     * @return array<string, string> file path relative to src/ => file content
     */
    private function sourceFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path => $info) {
            if ($info->getExtension() === "php") {
                $files[substr($path, strlen(self::SRC))] = file_get_contents($path);
            }
        }

        return $files;
    }
}
