<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\AssemblyAiParser;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\AwsTranscribeParser;
use SubtitleToolbox\Parsers\DeepgramParser;
use SubtitleToolbox\Parsers\EbuStlParser;
use SubtitleToolbox\Parsers\FfMetadataChaptersParser;
use SubtitleToolbox\Parsers\GoogleSpeechParser;
use SubtitleToolbox\Parsers\HtmlTranscriptParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\LyricsParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\Parsers\OgmChaptersParser;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SbvParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\SubViewerParser;
use SubtitleToolbox\Parsers\TmPlayerParser;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class FormatDetectorTest extends TestCase
{
    private const DIR = __DIR__ . "/files/";

    private const PARSERS = [
        "ass"      => AssParser::class,
        "assemblyai" => AssemblyAiParser::class,
        "aws-transcribe" => AwsTranscribeParser::class,
        "deepgram" => DeepgramParser::class,
        "google-speech" => GoogleSpeechParser::class,
        "chapters/ffmetadata" => FfMetadataChaptersParser::class,
        "chapters/ogm"        => OgmChaptersParser::class,
        "chapters/podcast"    => PodcastChaptersParser::class,
        "html"     => HtmlTranscriptParser::class,
        "json"     => JsonParser::class,
        "lrc"      => LyricsParser::class,
        "microdvd" => MicroDvdParser::class,
        "mpl2"     => Mpl2Parser::class,
        "mpsub"    => MpSubParser::class,
        "podcast"  => PodcastTranscriptParser::class,
        "sami"     => SamiParser::class,
        "sbv"      => SbvParser::class,
        "srt"      => SubRipParser::class,
        "subviewer" => SubViewerParser::class,
        "stl"      => EbuStlParser::class,
        "tmplayer" => TmPlayerParser::class,
        "ttml"     => TtmlParser::class,
        "vtt"      => WebVttParser::class,
        "whisper"  => WhisperJsonParser::class,
        "youtube"  => YouTubeTimedTextParser::class,
    ];

    // These fixtures break their own format on purpose, so their parser rejects them.
    private const BROKEN_FIXTURES = [
        "sbv/missing_milli_digits.sbv"  => null,
        "sbv/srt_timestamps.sbv"        => null,
        "vtt/missing_webvtt_header.vtt" => SubRipParser::class,
    ];


    public static function fixtures(): array
    {
        $fixtures = [];
        foreach (self::PARSERS as $directory => $parserClass) {
            foreach (array_merge(glob(self::DIR . "$directory/*.*"), glob(self::DIR . "$directory/real/*.*")) as $path) {
                $name = substr($path, strlen(self::DIR));
                if (!str_ends_with($path, ".md") && !str_ends_with($path, ".php") && !array_key_exists($name, self::BROKEN_FIXTURES)) {
                    $fixtures[$name] = [$name, $parserClass];
                }
            }
        }

        return $fixtures;
    }


    public static function realFiles(): array
    {
        return array_filter(self::fixtures(), fn (array $fixture): bool => str_contains($fixture[0], "/real/"));
    }


    public static function brokenFixtures(): array
    {
        return array_map(null, array_keys(self::BROKEN_FIXTURES), array_values(self::BROKEN_FIXTURES));
    }


    #[DataProvider("fixtures")]
    public function testDetectsTheFormatOfEveryFixture(string $file, string $parserClass): void
    {
        $this->assertSame($parserClass, Subtitle::detectParser(file_get_contents(self::DIR . $file)));
    }


    #[DataProvider("brokenFixtures")]
    public function testDetectsBrokenFixturesByTheirShape(string $file, ?string $parserClass): void
    {
        $this->assertSame($parserClass, Subtitle::detectParser(file_get_contents(self::DIR . $file)));
    }


    #[DataProvider("realFiles")]
    public function testParseWithoutParserGivesTheSameCuesAsTheDetectedParser(string $file, string $parserClass): void
    {
        $content = file_get_contents(self::DIR . $file);
        if ($parserClass === MicroDvdParser::class && !str_starts_with(ltrim($content), "{1}{1}")) {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("The frame rate is unknown.");
        }

        $detected = Subtitle::parse($content);
        $explicit = Subtitle::parse($content, $parserClass);

        $this->assertEquals($explicit->getCues(), $detected->getCues());
    }


    public static function signatures(): array
    {
        return [
            "WebVTT with title"          => ["WEBVTT - Weather report\n\n00:01.000 --> 00:02.000\nRain\n", WebVttParser::class],
            "WebVTT header only"         => ["WEBVTT", WebVttParser::class],
            "TTML with XML declaration"  => ["<?xml version=\"1.0\"?>\n<!-- made by hand -->\n<tt xmlns=\"http://www.w3.org/ns/ttml\"/>", TtmlParser::class],
            "TTML with prefixed root"    => ["<tt:tt xmlns:tt=\"http://www.w3.org/ns/ttml\"></tt:tt>", TtmlParser::class],
            "SAMI in lower case"         => ["<sami><body></body></sami>", SamiParser::class],
            "SSA header"                 => ["[Script Info]\r\nScriptType: v4.00\r\n", AssParser::class],
            "MPSub with FORMAT first"    => ["FORMAT=25\n\n0 50\nHello\n", MpSubParser::class],
            "MPSub with header comment"  => ["TITLE=Bakery\nFORMAT=TIME   # seconds\n\n1 2\nHello\n", MpSubParser::class],
            "MicroDVD with fps line"     => ["{1}{1}25\n{24}{72}Hello\n", MicroDvdParser::class],
            "MicroDVD without end frame" => ["{24}{}Hello\n", MicroDvdParser::class],
            "SubRip with dot"            => ["1\n00:00:01.000 --> 00:00:04.000\nHello\n", SubRipParser::class],
            "SBV"                        => ["0:00:01.500,0:00:04.000\nHello\n", SbvParser::class],
            "SubViewer 2 information"    => ["[INFORMATION]\r\n[TITLE]Bakery\r\n[END INFORMATION]\r\n", SubViewerParser::class],
            "SubViewer 2 timing line"    => ["00:00:01.50,00:00:04.00\nHello[br]world\n", SubViewerParser::class],
            "SubViewer 2 after [SUBTITLE]" => ["[SUBTITLE]\n[COLF]&HFFFFFF,[SIZE]18\n00:00:01.50,00:00:04.00\nHello\n", SubViewerParser::class],
            "SubViewer 1"                => ["[TITLE]\nBakery\n[DELAY]\n0\n******** START SCRIPT ********\n[00:00:01]\nHello\n", SubViewerParser::class],
            "SBV with three digits"      => ["0:00:01.500,0:00:04.000\nHello[br]world\n", SbvParser::class],
            "SBV with two hour digits"   => ["00:00:01.500,00:00:04.000\nHello\n", SbvParser::class],
            "LRC with ID tag"            => ["[ti:Morning Train]\n[00:12.00]Hello\n", LyricsParser::class],
            "LRC without fraction"       => ["[00:12]Hello\n", LyricsParser::class],
            "JSON with cues first"       => ["{\"cues\": [], \"metadata\": {\"title\": \"version\"}, \"version\": 1}", JsonParser::class],
            "JSON with spaces"           => ["{\n  \"version\" : 1 ,\n  \"cues\" : [ ]\n}\n", JsonParser::class],
            "EBU STL at 30 fps"          => [str_pad("865STL30.011", 1024), EbuStlParser::class],
            "JSON with a segments key"   => ["{\"version\": 1, \"formatData\": {\"x\": {\"segments\": [1]}}, \"cues\": []}", JsonParser::class],
            "Whisper JSON"               => ["{\"text\": \" Hello\", \"segments\": [{\"id\": 0, \"start\": 0.0, \"end\": 2.0, \"text\": \" Hello\"}], \"language\": \"en\"}",
                                             WhisperJsonParser::class],
            "whisper.cpp JSON"           => ["{\n\t\"systeminfo\": \"\",\n\t\"transcription\": [\n\t]\n}\n", WhisperJsonParser::class],
            "Amazon Transcribe"          => ["{\"jobName\": \"a\", \"results\": {\"transcripts\": [{\"transcript\": \"\"}], \"items\": []}}",
                                             AwsTranscribeParser::class],
            "Amazon Transcribe speakers" => ["{\"results\": {\"speaker_labels\": {\"segments\": []}, \"transcripts\": []}}", AwsTranscribeParser::class],
            "Deepgram"                   => ["{\"metadata\": {\"channels\": 1}, \"results\": {\"channels\": [{\"alternatives\": []}]}}",
                                             DeepgramParser::class],
            "Deepgram with topics"       => ["{\"results\": {\"topics\": {\"segments\": []}, \"channels\": [{\"detected_language\": \"en\", " .
                                             "\"alternatives\": []}]}}", DeepgramParser::class],
            "AssemblyAI"                 => ["{\"id\": \"x\", \"audio_url\": \"https://example.com/a.mp3\", \"words\": null}", AssemblyAiParser::class],
            "AssemblyAI words only"      => ["{\"words\": [{\"text\": \"Hi\", \"start\": 250, \"end\": 650}]}", AssemblyAiParser::class],
            "Google V1"                  => ["{\"results\": [{\"alternatives\": [{\"transcript\": \"hi\"}], \"resultEndTime\": \"1s\"}]}",
                                             GoogleSpeechParser::class],
            "Google V2 end first"        => ["{\"results\": [{\"resultEndOffset\": \"1s\", \"alternatives\": []}], \"metadata\": {}}",
                                             GoogleSpeechParser::class],
            "Whisper JSON with words"    => ["{\"segments\": [], \"words\": [{\"word\": \"Hi\", \"start\": 0, \"end\": 1}], " .
                                             "\"results\": [{\"x\": 1}]}", WhisperJsonParser::class],
            "YouTube json3"              => ["{\"wireMagic\": \"pb3\", \"events\": [ {\"id\": 1}, {\"tStartMs\": 0, \"segs\": []} ]}", YouTubeTimedTextParser::class],
            "YouTube srv3"               => ["<?xml version=\"1.0\" encoding=\"utf-8\" ?><timedtext format=\"3\">\n<body>\n</body>\n</timedtext>\n",
                                             YouTubeTimedTextParser::class],
            "YouTube srv1"               => ["<transcript><text start=\"1.2\" dur=\"2.3\">Hello</text></transcript>", YouTubeTimedTextParser::class],
            "JSON with an events list"   => ["{\"version\": 1, \"formatData\": {\"x\": {\"events\": [{\"tStartMs\": 0}]}}, \"cues\": []}", JsonParser::class],
            "MPL2"                       => ["[10][25]Hello|/world\n", Mpl2Parser::class],
            "MicroDVD next to MPL2"      => ["{10}{25}Hello|world\n", MicroDvdParser::class],
            "LRC next to MPL2"           => ["[00:01.00]Hello\n[00:02.50]world\n", LyricsParser::class],
            "LRC with minutes only"      => ["[00:01]Hello\n", LyricsParser::class],
            "TMPlayer"                   => ["00:00:01:Hello|world\n", TmPlayerParser::class],
            "TMPlayer+ with equals sign" => ["0:00:01=Hello\n", TmPlayerParser::class],
            "TMPlayer+ with line numbers" => ["00:00:01,1=Hello\n00:00:01,2=world\n", TmPlayerParser::class],
            "SBV next to TMPlayer"       => ["0:00:01.000,0:00:02.000\nHello\n", SbvParser::class],
            "Podcast chapters first"     => ["{\"chapters\": [], \"version\": \"1.2.0\"}", PodcastChaptersParser::class],
            "JSON with a chapters list"  => ["{\"version\": 1, \"formatData\": {\"chapters\": {\"chapters\": []}}, \"cues\": []}", JsonParser::class],
            "FFmpeg metadata"            => [";FFMETADATA1\ntitle=Meetup\n", FfMetadataChaptersParser::class],
            "OGM with blank line"        => ["CHAPTER00 = 00:00:00.000\r\n\r\nCHAPTER00NAME=Intro\r\n", OgmChaptersParser::class],
            "Podcasting 2.0 JSON"        => ["{\"version\": \"1.0.0\", \"segments\": [{\"speaker\": \"Anna\", \"startTime\": 0.5, \"body\": \"I\"}]}",
                                             PodcastTranscriptParser::class],
            "Podcasting 2.0 JSON body first" => ["{\"segments\":[{\"body\":\"Hi\",\"endTime\":1,\"startTime\":0}]}", PodcastTranscriptParser::class],
            "JSON with podcast segments" => ["{\"version\": 1, \"formatData\": {\"x\": {\"segments\": [{\"startTime\": 0, \"body\": \"\"}]}}, \"cues\": []}",
                                             JsonParser::class],
            "Whisper JSON with a body"   => ["{\"segments\": [{\"start\": 0.0, \"end\": 2.0, \"text\": \" Hello\", \"body\": 1}]}", WhisperJsonParser::class],
            "Podcast chapters, not a transcript" => ["{\"version\": \"1.2.0\", \"chapters\": [{\"startTime\": 0, \"title\": \"Intro\"}]}",
                                             PodcastChaptersParser::class],
            "Podcast transcript, not chapters" => ["{\"version\": \"1.0.0\", \"chapters\": [], \"segments\": [{\"startTime\": 0, \"body\": \"Hi\"}]}",
                                             PodcastTranscriptParser::class],
            "Podcasting 2.0 HTML"        => ["<cite>Anna:</cite>\n<time>0:00</time>\n<p>Hello</p>\n", HtmlTranscriptParser::class],
            "HTML document with time"    => ["<!DOCTYPE html>\n<html><body>\n<CITE>Anna:</CITE>\n<TIME>0:00</TIME><p>Hi</p></body></html>", HtmlTranscriptParser::class],
        ];
    }


    public static function subFiles(): array
    {
        $files = [];
        foreach (["microdvd" => MicroDvdParser::class, "mpsub" => MpSubParser::class, "subviewer" => SubViewerParser::class] as $directory => $parserClass) {
            foreach (glob(self::DIR . "$directory/real/*.sub") as $path) {
                $files[basename($path)] = [$path, $parserClass];
            }
        }

        return $files;
    }


    #[DataProvider("subFiles")]
    public function testSubFilesDetectByTheirContent(string $path, string $parserClass): void
    {
        $this->assertSame($parserClass, FormatDetector::detect(file_get_contents($path)));
    }


    #[DataProvider("signatures")]
    public function testDetectsSignatures(string $content, string $parserClass): void
    {
        $this->assertSame($parserClass, FormatDetector::detect($content));
    }


    public function testIgnoresUtf8BomAndLeadingBlankLines(): void
    {
        $content = "\xEF\xBB\xBF\r\n\r\n  \n1\r\n00:00:01,000 --> 00:00:04,000\r\nHello\r\n";

        $this->assertSame(SubRipParser::class, Subtitle::detectParser($content));
        $this->assertCount(1, Subtitle::parse($content)->getCues());
    }


    public static function unknownContent(): array
    {
        return [
            "empty string"             => [""],
            "blank lines"              => ["\n\r\n  \n"],
            "BOM only"                 => ["\xEF\xBB\xBF"],
            "plain text"               => ["The train to the coast leaves at 7:15.\nBring a coat.\n"],
            "JSON with version string" => ["{\"version\": \"1\", \"cues\": []}"],
            "JSON with cues as text"   => ["{\"version\": 1, \"text\": \"\\\"cues\\\": [\"}"],
            "JSON"                     => ["{\"cues\": [{\"start\": 1, \"end\": 2, \"text\": \"Hello\"}]}"],
            "HTML"                     => ["<!DOCTYPE html>\n<html><head><title>Bakery</title></head><body><p>Hello</p></body></html>"],
            "XHTML"                    => ["<?xml version=\"1.0\"?>\n<html xmlns=\"http://www.w3.org/1999/xhtml\"></html>"],
            "INI section with colon"   => ["[server:main]\nport=80\n"],
            "key value lines"          => ["TITLE=Bakery\nAUTHOR=Jane Doe\n"],
            "number without timing"    => ["1\nHello\n"],
            "WEBVTT inside a word"     => ["WEBVTTX\n"],
            "EBU STL at 24 fps"        => [str_pad("850STL24.011", 1024)],
            "events without tStartMs"  => ["{\"events\": [{\"start\": 1}]}"],
            "events as text"           => ["{\"text\": \"\\\"events\\\": [{\\\"tStartMs\\\": 1}]\"}"],
            "timedtext inside a word"  => ["<timedtextx/>"],
            "MPL2 with a letter"       => ["[1a][25]Hello\n"],
            "clock time in text"       => ["10:30 is the time.\n"],
            "chapters without version" => ["{\"chapters\": [{\"startTime\": 0}]}"],
            "chapters as text"         => ["{\"version\": \"1\", \"text\": \"\\\"chapters\\\": [\"}"],
            "OGM without name line"    => ["CHAPTER01=00:00:00.000\nCHAPTER02=00:01:00.000\n"],
            "YouTube chapters"         => ["0:00 Intro\n2:48 Hearing aids\n4:20 Progress report\n"],
            "HTML without time"        => ["<cite>Anna:</cite>\n<p>Hello</p>\n"],
            "cite and time as text"    => ["Hello <cite> and <time>\n"],
        ];
    }


    #[DataProvider("unknownContent")]
    public function testReturnsNullForUnknownContent(string $content): void
    {
        $this->assertNull(Subtitle::detectParser($content));
    }


    public function testParseWithoutParserThrowsForUnknownContent(): void
    {
        $this->expectException(InvalidParserException::class);
        $this->expectExceptionMessage("The subtitle format of the content is unknown.");

        Subtitle::parse("Just some text.");
    }
}
