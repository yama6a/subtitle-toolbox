<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttRealFileTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/vtt/real/";


    public static function realFileProvider(): array
    {
        return [
            "astisub_carriage_return" => ["astisub_carriage_return.vtt", 3, 0.0, 3.766, "The bakery on the corner opens\nat six o'clock every morning.", 10.733, 14.066, "and sells the last one\nbefore the noon train."],
            "astisub_html_entities"   => ["astisub_html_entities.vtt", 3, 0.331, 3.75, "The morning train left the station on time, \u{00A0}\n&amp; the rain stopped at noon.", 6.331, 9.675, "the temperature is &lt; ten degrees today."],
            "mantas_styles"           => ["mantas_styles.vtt", 1, 0.0, 10.0, "Hello world.", 0.0, 10.0, "Hello world."],
            "w3c_chapters"            => ["w3c_chapters.vtt", 4, 0.0, 10.7, "Title Slide", 110.1, 213.0, "Requirements of a Video text format"],
            "w3c_comments"            => ["w3c_comments.vtt", 2, 1.0, 4.0, "Never drink liquid nitrogen.", 5.0, 9.0, "— It will perforate your stomach.\n— You could die."],
            "w3c_cue_settings"        => ["w3c_cue_settings.vtt", 3, 0.0, 4.0, "Where did he go?", 4.0, 6.5, "What are you waiting for?"],
            "w3c_identifiers"         => ["w3c_identifiers.vtt", 3, 0.0, 2.0, "This is a test.", 4.0, 5.0, "Transcrit par Célestes™"],
            "w3c_regions"             => ["w3c_regions.vtt", 6, 0.0, 20.0, "<v Fred>Hi, my name is Fred", 12.5, 32.5, "<v Fred>OK, let's go."],
            "w3c_styles"              => ["w3c_styles.vtt", 1, 0.0, 10.0, "Hello <b>world</b>.", 0.0, 10.0, "Hello <b>world</b>."],
            "w3c_timestamps"          => ["w3c_timestamps.vtt", 3, 0.0, 8.0, "<c>No match (no timestamps)</c>", 16.0, 24.0, "<00:00:16.000> <c>This</c>\n<00:00:18.000> <c>can</c>\n<00:00:20.000> <c>match</c>\n<00:00:22.000> <c>:past/:future</c>\n<00:00:24.000>"],
            "w3c_voices"              => ["w3c_voices.vtt", 13, 11.0, 13.0, "<v Anna Berg>We are at the train station", 35.5, 38.0, "<v Anna Berg>You know the rain is so heavy my umbrella is leaking here."],
            "webvttpy_comments"       => ["webvttpy_comments.vtt", 3, 135.0, 140.0, "- Det regnar i dag.\n- Det är kallt ute.", 145.0, 150.0, "- Ta ett paraply"],
            "webvttpy_netflix"        => ["webvttpy_netflix.vtt", 30, 7.96, 9.48, "[Rosa] <i>En 1928,</i>", 107.76, 108.8, "Rápido."],
            "webvttpy_youtube"        => ["webvttpy_youtube.vtt", 4, 286.07, 286.47, "okay", 305.069, 305.4, "the train<c.colorE5E5E5> leaves</c><c.colorCCCCCC> at ten today\n</c>"],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        string $firstText,
        float $lastStart,
        float $lastEnd,
        string $lastText
    ): void {
        $cues = array_values(Subtitle::parse(file_get_contents(self::DIR . $file), WebVttParser::class)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = end($cues);
        $this->assertSame([$lastStart, $lastEnd, $lastText], [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsAllData(string $file): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . $file), WebVttParser::class);
        $output   = $subtitle->format(WebVttFormatter::class);
        $reparsed = Subtitle::parse($output, WebVttParser::class);

        $this->assertSame($this->describe($subtitle), $this->describe($reparsed));
        $this->assertSame($output, $reparsed->format(WebVttFormatter::class));
    }


    public function testRealFileRegionsAndCueSettings(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "w3c_regions.vtt"), WebVttParser::class);
        $cue      = $subtitle->getCues()[1];

        $this->assertSame(["fred", "bill"], array_column($subtitle->getFormatData("vtt")["regions"], "id"));
        $this->assertSame(["region" => "bill", "align" => "right"], $cue->getFormatData("vtt"));
        $this->assertSame(3, $cue->getAlignment());
    }


    public function testRealFileYouTubeHeaderLinesAreKept(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "webvttpy_youtube.vtt"), WebVttParser::class);

        $headerLines = $subtitle->getFormatData("vtt")["headerLines"];
        $this->assertSame(["Kind: captions", "Language: en", "Style:"], array_slice($headerLines, 0, 3));
        $this->assertSame("##", end($headerLines));
    }


    public function testRealFileCommentsAndHeaderText(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "webvttpy_comments.vtt"), WebVttParser::class);

        $this->assertSame(["header" => "- Translation of a weather report"], $subtitle->getFormatData("vtt"));
        $this->assertSame(
            [0, 2, 3],
            array_column($subtitle->getComments(), "beforeCueIndex")
        );
        $this->assertSame("end of file", $subtitle->getComments()[2]["text"]);
    }


    private function describe(Subtitle $subtitle): array
    {
        return [
            $subtitle->getFormatData("vtt"),
            $subtitle->getComments(),
            array_map(fn (SubtitleCue $cue): array => [
                $cue->getStart(),
                $cue->getEnd(),
                // The formatter strips class names such as <c.yellow>, so the round trip cannot keep them.
                preg_replace("/<c\.[^>]*>/", "", $cue->getLines()),
                $cue->getAlignment(),
                $cue->getFormatData("vtt"),
            ], $subtitle->getCues()),
        ];
    }
}
