<?php

namespace SubtitleToolbox\Exceptions;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\DualSubtitleOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Formatters\EbuStlFormatter;
use SubtitleToolbox\Formatters\IttFormatter;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\MpSubFormatter;
use SubtitleToolbox\Formatters\PlainTextFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\SubViewerFormatter;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\HearingImpairedOptions;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Ocr\OcrResult;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Sync\ReferenceSyncOptions;

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
    ];

    private const IDX = "# VobSub index file, v7 (do not modify this line!)\nsize: 720x576\n" .
                        "palette: 000000, f0f0f0, cccccc, 999999, 3333fa, 1111bb, fa3333, bb1111, " .
                        "33fa33, 11bb11, fafa33, bbbb11, fa33fa, bb11bb, 33fafa, 11bbbb\n";

    private const IDX_WITH_TRACK = self::IDX . "id: en, index: 0\ntimestamp: 00:00:01:000, filepos: 000000000\n";

    private const TTML = '<tt xmlns="http://www.w3.org/ns/ttml"><body><div>%s</div></body></tt>';


    private static function pgsSegment(int $type, string $data): string
    {
        return "PG" . pack("NNCn", 0, 0, $type, strlen($data)) . $data;
    }


    private static function vobSubPacket(string $unit): string
    {
        $body = "\x81\x00\x00\x20" . $unit;

        return "\x00\x00\x01\xBA\x44\x00\x04\x00\x04\x01\x01\x89\xC3\xFA\xFF\xFF\x00\x00\x01\xBD" . pack("n", strlen($body)) . $body;
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
            "ArrayConversion.php: format data no object"    => [fn () => self::fromArray(["formatData" => ["srt" => 5]]), ...$parsing],
            "ArrayConversion.php: map no object"            => [fn () => self::fromArray(["metadata" => 5]), ...$parsing],
            "ArrayConversion.php: comments no list"         => [fn () => self::fromArray(["comments" => 5]), ...$parsing],
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
            "Fixes.php: minimum duration 0"                 => [fn () => self::subtitle()->extendShortCues(0), ...$invalid],
            "Fixes.php: maximum characters 0"               => [fn () => self::subtitle()->wrapLines(0), ...$invalid],
            "Fixes.php: negative gap"                       => [fn () => self::subtitle()->fixOverlaps(-1), ...$invalid],
            "Formatters/EbuStlFormatter.php: frame rate 24" => [fn () => self::subtitle()->format(EbuStlFormatter::class,
                [EbuStlFormatter::OPTION_FRAME_RATE => 24]), ...$invalid],
            "Formatters/EbuStlFormatter.php: code table 09" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["gsi" => ["CCT" => "09"]])->format(EbuStlFormatter::class), ...$invalid],
            "Formatters/EbuStlFormatter.php: subtitle number 65536" => [fn () => self::subtitle()->setFormatData(EbuStlParser::FORMAT_DATA_KEY,
                ["firstSubtitleNumber" => 65536])->format(EbuStlFormatter::class), ...$invalid],
            "Formatters/EbuStlFormatter.php: text too long" => [fn () => (new Subtitle())->addCue(new SubtitleCue(1, 2, str_repeat("a", 30000)))
                ->format(EbuStlFormatter::class), ...$invalid],
            "Formatters/IttFormatter.php: no frame rate"    => [fn () => self::subtitle()->format(IttFormatter::class), ...$invalid],
            "Formatters/IttFormatter.php: unsupported frame rate" => [fn () => self::subtitle()->format(IttFormatter::class,
                [IttFormatter::OPTION_FRAME_RATE => 50]), ...$invalid],
            "Formatters/MicroDvdFormatter.php: no frame rate" => [fn () => self::subtitle()->format(MicroDvdFormatter::class), ...$invalid],
            "Formatters/MpSubFormatter.php: fractional frame rate" => [fn () => self::subtitle()->format(MpSubFormatter::class,
                [MpSubFormatter::OPTION_FRAME_RATE => 25.5]), ...$invalid],
            "Formatters/PlainTextFormatter.php: paragraph gap" => [fn () => self::subtitle()->format(PlainTextFormatter::class,
                [PlainTextFormatter::OPTION_PARAGRAPH_GAP => "2"]), ...$invalid],
            "Formatters/SubViewerFormatter.php: version 3"  => [fn () => self::subtitle()->format(SubViewerFormatter::class,
                [SubViewerFormatter::OPTION_VERSION => 3]), ...$invalid],
            "Formatters/SubtitleFormatter.php: line ending" => [fn () => self::subtitle()->format(SubRipFormatter::class,
                [SubtitleFormatter::OPTION_LINE_ENDING => "\r"]), ...$invalid],
            "Formatters/SubtitleFormatter.php: bom"         => [fn () => self::subtitle()->format(SubRipFormatter::class,
                [SubtitleFormatter::OPTION_BOM => "yes"]), ...$invalid],
            "Formatters/TtmlFormatter.php: stored head"     => [fn () => self::subtitle()->setFormatData(TtmlParser::FORMAT, ["head" => "<p/>"])
                ->format(TtmlFormatter::class), InvalidFormatterException::class, InvalidFormatterException::class],
            "FrameRate.php: frame rate 0"                   => [fn () => new FrameRate(0), ...$invalid],
            "HearingImpairedOptions.php: empty bracket"     => [fn () => new HearingImpairedOptions(customBrackets: [["{", ""]]), ...$invalid],
            "Image/CueImage.php: width 0"                   => [fn () => new CueImage("png", 0, 0, 0, 1, 1, 1), ...$invalid],
            "Image/CueImage.php: no image"                  => [fn () => CueImage::fromCue(new SubtitleCue(1, 2, "text")), ...$invalid],
            "Image/CueImage.php: no integer x"              => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["png" => "png"])), ...$invalid],
            "Image/CueImage.php: no png"                    => [fn () => CueImage::fromCue((new SubtitleCue(1, 2))
                ->setFormatData(CueImage::FORMAT_DATA_KEY, ["x" => 0, "y" => 0, "width" => 1, "height" => 1,
                                                            "screenWidth" => 1, "screenHeight" => 1])), ...$invalid],
            "Image/PngEncoder.php: width 0"                 => [fn () => PngEncoder::encode(0, 1, []), ...$invalid],
            "Image/PngEncoder.php: pixel count"             => [fn () => PngEncoder::encode(1, 1, []), ...$invalid],
            "Ocr/OcrResult.php: line is no string"          => [fn () => new OcrResult([5]), ...$invalid],
            "Ocr/OcrResult.php: confidence above 1"         => [fn () => new OcrResult(["text"], 2), ...$invalid],
            "Parsers/AssParser.php: no events section"      => [fn () => (new AssParser())->parse("[Script Info]\nTitle: x\n"), ...$parsing],
            "Parsers/AssParser.php: too few fields"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Start, End, Text\n" .
                                                                                                  "Dialogue: 0,0:00:01.00\n"), ...$parsing],
            "Parsers/AssParser.php: no Start field"         => [fn () => (new AssParser())->parse("[Events]\nFormat: Layer, Text\n" .
                                                                                                  "Dialogue: 0,text\n"), ...$parsing],
            "Parsers/AssParser.php: invalid time"           => [fn () => (new AssParser())->parse("[Events]\nFormat: Start, End, Text\n" .
                                                                                                  "Dialogue: soon,0:00:02.00,text\n"), ...$parsing],
            "Parsers/EbuStlParser.php: no GSI block"        => [fn () => (new EbuStlParser())->parse("STL"), ...$parsing],
            "Parsers/EbuStlParser.php: partial TTI block"   => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1025)), ...$parsing],
            "Parsers/EbuStlParser.php: disk format code"    => [fn () => (new EbuStlParser())->parse(str_repeat(" ", 1024)), ...$parsing],
            "Parsers/EbuStlParser.php: code table 09"       => [fn () => (new EbuStlParser())->parse(
                str_pad("850STL25.01109", 1024, " ")), ...$parsing],
            "Parsers/JsonParser.php: no JSON"               => [fn () => (new JsonParser())->parse("{"), ...$parsing],
            "Parsers/JsonParser.php: root no object"        => [fn () => (new JsonParser())->parse("[1]"), ...$parsing],
            "Parsers/JsonParser.php: invalid base64"        => [fn () => (new JsonParser())->parse(
                '{"version": 1, "cues": [], "formatData": {"stl": {"base64": "!"}}}'), ...$parsing],
            "Parsers/LyricsParser.php: negative duration"   => [fn () => new LyricsParser(-1), ...$invalid],
            "Parsers/MicroDvdParser.php: no frame rate"     => [fn () => (new MicroDvdParser())->parse("{0}{25}text"), ...$parsing],
            "Parsers/MicroDvdParser.php: frame rate 0"      => [fn () => (new MicroDvdParser())->parse("{1}{1}0\n{0}{25}text"), ...$parsing],
            "Parsers/MicroDvdParser.php: no cue"            => [fn () => (new MicroDvdParser(25))->parse("text"), ...$parsing],
            "Parsers/MpSubParser.php: no timing line"       => [fn () => (new MpSubParser())->parse("FORMAT=TIME\ntext\n"), ...$parsing],
            "Parsers/MpSubParser.php: negative duration"    => [fn () => (new MpSubParser())->parse("FORMAT=TIME\n0 -1\ntext\n"), ...$parsing],
            "Parsers/MpSubParser.php: no text lines"        => [fn () => (new MpSubParser())->parse("FORMAT=TIME\n0 1\n\n"), ...$parsing],
            "Parsers/MpSubParser.php: unknown FORMAT"       => [fn () => (new MpSubParser())->parse("FORMAT=FAST\n"), ...$parsing],
            "Parsers/MpSubParser.php: frame rate 0"         => [fn () => (new MpSubParser())->parse("FORMAT=0\n"), ...$parsing],
            "Parsers/PgsParser.php: last cue duration 0"    => [fn () => new PgsParser(0), ...$invalid],
            "Parsers/PgsParser.php: no magic bytes"         => [fn () => (new PgsParser())->parse("XG"), ...$parsing],
            "Parsers/PgsParser.php: cut off header"         => [fn () => (new PgsParser())->parse("PG\0\0"), ...$parsing],
            "Parsers/PgsParser.php: cut off data"           => [fn () => (new PgsParser())->parse(substr(self::pgsSegment(0x14, "\0\0"), 0, -1)),
                                                                ...$parsing],
            "Parsers/PgsParser.php: cut off presentation"   => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16, "\0\0")), ...$parsing],
            "Parsers/PgsParser.php: cut off object"         => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1")), ...$parsing],
            "Parsers/PgsParser.php: cut off cropping"       => [fn () => (new PgsParser())->parse(self::pgsSegment(0x16,
                "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\0\0\x80\0\0\0\0")), ...$parsing],
            "Parsers/PgsParser.php: cut off definition"     => [fn () => (new PgsParser())->parse(self::pgsSegment(0x15, "\0\1\0\x80")), ...$parsing],
            "Parsers/PgsParser.php: short bitmap"           => [fn () => (new PgsParser())->parse(
                self::pgsSegment(0x16, "\x02\xD0\x02\x40\x10\0\1\x80\0\0\1" . "\0\7\0\0\0\0\0\0") .
                self::pgsSegment(0x14, "\0\0\1\x10\x80\x80\xFF") .
                self::pgsSegment(0x15, "\0\7\0\xC0\0\0\7\0\4\0\2\1\1\0\0") .
                self::pgsSegment(0x80, "")), ...$parsing],
            "Parsers/SamiParser.php: negative duration"     => [fn () => new SamiParser(null, -1), ...$invalid],
            "Parsers/SamiParser.php: invalid UTF-8"         => [fn () => (new SamiParser())->parse("<SAMI>\xFF</SAMI>"), ...$parsing],
            "Parsers/SamiParser.php: no Start attribute"    => [fn () => (new SamiParser())->parse("<SAMI><BODY><SYNC>text</BODY></SAMI>"),
                                                                ...$parsing],
            "Parsers/SamiParser.php: unknown class"         => [fn () => (new SamiParser("FRCC"))->parse(
                "<SAMI><BODY><SYNC Start=0><P Class=ENCC>text</BODY></SAMI>"), ...$parsing],
            "Parsers/SbvParser.php: no timestamps"          => [fn () => (new SbvParser())->parse("text\nmore"), ...$parsing],
            "Parsers/SbvParser.php: no text lines"          => [fn () => (new SbvParser())->parse("0:00:01.000,0:00:02.000"), ...$parsing],
            "Parsers/SbvParser.php: invalid time"           => [fn () => (new SbvParser())->parse("soon,0:00:02.000\ntext"), ...$parsing],
            "Parsers/SubRipParser.php: no cue number"       => [fn () => (new SubRipParser())->parse("x\n00:00:01,000 --> 00:00:02,000\ntext"),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: no timestamps"       => [fn () => (new SubRipParser())->parse("1\ntext\nmore"), ...$parsing],
            "Parsers/SubRipParser.php: no text lines"       => [fn () => (new SubRipParser())->parse("1\n00:00:01,000 --> 00:00:02,000"),
                                                                ...$parsing],
            "Parsers/SubRipParser.php: invalid time"        => [fn () => (new SubRipParser())->parse("1\nsoon --> 00:00:02,000\ntext"),
                                                                ...$parsing],
            "Parsers/SubViewerParser.php: negative duration" => [fn () => new SubViewerParser(-1), ...$invalid],
            "Parsers/SubViewerParser.php: version 1 header" => [fn () => (new SubViewerParser())->parse("text\n" . SubViewerParser::START_SCRIPT . "\n"), ...$parsing],
            "Parsers/SubViewerParser.php: version 2 header" => [fn () => (new SubViewerParser())->parse("text\n"), ...$parsing],
            "Parsers/TtmlParser.php: no tt root"            => [fn () => (new TtmlParser())->parse("<html/>"), ...$parsing],
            "Parsers/TtmlParser.php: invalid time"          => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="soon" end="2s">text</p>')), ...$parsing],
            "Parsers/TtmlParser.php: empty file"            => [fn () => (new TtmlParser())->parse(""), ...$parsing],
            "Parsers/TtmlParser.php: not well-formed"       => [fn () => (new TtmlParser())->parse("<tt"), ...$parsing],
            "Parsers/TtmlParser.php: no end time"           => [fn () => (new TtmlParser())->parse(sprintf(self::TTML,
                '<p begin="1s">text</p>')), ...$parsing],
            "Parsers/VobSubParser.php: no track"            => [fn () => new VobSubParser(self::IDX), ...$parsing],
            "Parsers/VobSubParser.php: filepos outside"     => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse(""), ...$parsing],
            "Parsers/VobSubParser.php: no header line"      => [fn () => new VobSubParser("size: 720x576\n"), ...$parsing],
            "Parsers/VobSubParser.php: invalid size"        => [fn () => new VobSubParser(self::IDX . "size: 0x0\n"), ...$parsing],
            "Parsers/VobSubParser.php: invalid custom colors" => [fn () => new VobSubParser(self::IDX . "custom colors: yes\n"), ...$parsing],
            "Parsers/VobSubParser.php: invalid id"          => [fn () => new VobSubParser(self::IDX . "id: en, index: 99\n"), ...$parsing],
            "Parsers/VobSubParser.php: timestamp before id" => [fn () => new VobSubParser(self::IDX . "timestamp: 00:00:01:000, filepos: 0\n"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: invalid timestamp"   => [fn () => new VobSubParser(self::IDX . "id: en, index: 0\ntimestamp: soon\n"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no size"             => [fn () => new VobSubParser("# VobSub index file\nid: en, index: 0\n"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: no palette"          => [fn () => new VobSubParser("# VobSub index file\nsize: 720x576\n"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: short palette"       => [fn () => new VobSubParser(self::IDX . "palette: 000000\n"), ...$parsing],
            "Parsers/VobSubParser.php: invalid time"        => [fn () => new VobSubParser(self::IDX . "delay: soon\n"), ...$parsing],
            "Parsers/VobSubParser.php: no start code"       => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse("text"), ...$parsing],
            "Parsers/VobSubParser.php: cut off pack header" => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse("\0\0\1\xBA\x44\0"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: program end code"    => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse("\0\0\1\xB9\0\0"),
                                                                ...$parsing],
            "Parsers/VobSubParser.php: packet too long"     => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse(
                substr(self::vobSubPacket("\0\4\0\4"), 0, -1)), ...$parsing],
            "Parsers/VobSubParser.php: no control sequence" => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse(
                self::vobSubPacket("\0\2")), ...$parsing],
            "Parsers/VobSubParser.php: cut off command"     => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse(
                self::vobSubPacket("\0\x09\0\4\0\0\0\4\x05")), ...$parsing],
            "Parsers/VobSubParser.php: cut off bitmap"      => [fn () => (new VobSubParser(self::IDX_WITH_TRACK))->parse(
                self::vobSubPacket("\0\x15\0\4\0\0\0\4\x05\0\0\1\0\0\1\x06\0\x15\0\x15\xFF")), ...$parsing],
            "Parsers/WebVttParser.php: no WEBVTT"           => [fn () => (new WebVttParser())->parse("text"), ...$parsing],
            "Parsers/WebVttParser.php: unknown block"       => [fn () => (new WebVttParser())->parse("WEBVTT\n\ntext\nmore"), ...$parsing],
            "Parsers/WebVttParser.php: no empty header line" => [fn () => (new WebVttParser())->parse(
                "WEBVTT\n00:00:01.000 --> 00:00:02.000\ntext"), ...$parsing],
            "Parsers/WebVttParser.php: no text lines"       => [fn () => (new WebVttParser())->parse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000"),
                                                                ...$parsing],
            "Parsers/WebVttParser.php: invalid end time"    => [fn () => (new WebVttParser())->parse("WEBVTT\n\n00:00:01.000 --> soon\ntext"),
                                                                ...$parsing],
            "Parsers/WebVttParser.php: invalid start time"  => [fn () => (new WebVttParser())->parse("WEBVTT\n\nsoon --> 00:00:02.000\ntext"),
                                                                ...$parsing],
            "Retiming.php: scale factor 0"                  => [fn () => self::subtitle()->scale(0), ...$invalid],
            "Retiming.php: same old times"                  => [fn () => self::subtitle()->syncByTwoPoints(1, 1, 1, 2), ...$invalid],
            "Retiming.php: new times in reverse"            => [fn () => self::subtitle()->syncByTwoPoints(1, 2, 2, 1), ...$invalid],
            "StringHelpers.php: unknown encoding"           => [fn () => Subtitle::parse("text", SubRipParser::class, "NO-SUCH-ENCODING"),
                                                                ...$parsing],
            "Subtitle.php: unknown format"                  => [fn () => Subtitle::parse("text"),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: parser of the wrong type"        => [fn () => Subtitle::parse("text", \stdClass::class),
                                                                InvalidParserException::class, InvalidParserException::class],
            "Subtitle.php: formatter of the wrong type"     => [fn () => self::subtitle()->format(\stdClass::class),
                                                                InvalidFormatterException::class, InvalidFormatterException::class],
            "Subtitle.php: image cue without text"          => [fn () => (new Subtitle())->addCue($imageCue)->format(SubRipFormatter::class),
                                                                ImageCueWithoutTextException::class, ImageCueWithoutTextException::class],
            "Subtitle.php: remove a missing cue"            => [fn () => self::subtitle()->removeCue(9),
                                                                \RuntimeException::class, CueNotFoundException::class],
            "Subtitle.php: negative comment index"          => [fn () => self::subtitle()->addComment("note", -1), ...$invalid],
            "SubtitleCue.php: lines of the wrong type"      => [fn () => (new SubtitleCue())->setLines(5), ...$invalid],
            "SubtitleCue.php: alignment 10"                 => [fn () => (new SubtitleCue())->setAlignment(10), ...$invalid],
            "Sync/ReferenceSyncOptions.php: offsets in reverse" => [fn () => new ReferenceSyncOptions(5, 1), ...$invalid],
            "TextTransforms.php: empty search"              => [fn () => self::subtitle()->replaceText("", "x"), ...$invalid],
            "TextTransforms.php: invalid regex"             => [fn () => self::subtitle()->replaceText("/[/", "x", true), ...$invalid],
            "TextTransforms.php: unknown case mode"         => [fn () => self::subtitle()->changeCase("title"), ...$invalid],
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
