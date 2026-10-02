<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\SamiFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;

class EncodingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/encoding/";

    private const WINDOWS_OUTPUT = [
        SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
        SubtitleFormatter::OPTION_BOM         => false,
    ];


    public static function legacyFiles(): array
    {
        return [
            "French Windows-1252"  => [
                "french-windows-1252.srt", "Windows-1252", SubRipParser::class, SubRipFormatter::class,
                [1.0, 3.5, "Le café est fermé à midi."],
                [70.1, 72.9, "<i>Où êtes-vous, Noël ?</i>"],
            ],
            "Russian Windows-1251" => [
                "russian-windows-1251.srt", "Windows-1251", SubRipParser::class, SubRipFormatter::class,
                [2.0, 4.0, "Привет, как дела?"],
                [9.0, 11.5, "Ёлка стоит в углу."],
            ],
            "Japanese Shift_JIS"   => [
                "japanese-shift_jis.srt", "Shift_JIS", SubRipParser::class, SubRipFormatter::class,
                [1.2, 3.0, "こんにちは、元気ですか？"],
                [7.0, 9.8, "駅まで歩きましょう。"],
            ],
            "Korean CP949 SAMI"    => [
                "korean-cp949.smi", "CP949", SamiParser::class, SamiFormatter::class,
                [1.0, 3.5, "안녕하세요.\n기차가 곧 도착합니다."],
                [7.0, 9.0, "<font color=\"#ffff00\">감사합니다.</font>"],
            ],
        ];
    }


    #[DataProvider("legacyFiles")]
    public function testLegacyFileParsesWithItsSourceEncoding(
        string $file, string $encoding, string $parser, string $formatter, array $first, array $last
    ): void {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::parse($raw, $parser, $encoding);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($last, [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame($raw, iconv("UTF-8", $encoding, $subtitle->format($formatter, self::WINDOWS_OUTPUT)));
    }


    #[DataProvider("legacyFiles")]
    public function testFormatDetectionWorksOnTheConvertedContent(string $file, string $encoding, string $parser): void
    {
        $detected = Subtitle::parse(file_get_contents(self::DIR . $file), null, $encoding);

        $this->assertEquals(Subtitle::parse(file_get_contents(self::DIR . $file), $parser, $encoding), $detected);
    }


    public function testCp949SamiOnlyParsesWithTheSourceEncoding(): void
    {
        $raw = file_get_contents(self::DIR . "korean-cp949.smi");

        $this->assertSame("기차 안내", Subtitle::parse($raw, SamiParser::class, "CP949")->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("똠방각하가 왔습니다.", Subtitle::parse($raw, SamiParser::class, "CP949")->getCues()[1]->getText());

        $this->expectException(ParsingException::class);
        Subtitle::parse($raw, SamiParser::class);
    }


    public function testWithoutSourceEncodingInvalidUtf8BytesStayAsTheyAre(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "french-windows-1252.srt"), SubRipParser::class);

        $this->assertSame("Le caf\xE9 est ferm\xE9 \xE0 midi.", $subtitle->getCues()[0]->getText());
    }


    public function testUtf16LeFileWithBomParsesWithoutSourceEncoding(): void
    {
        $raw      = file_get_contents(self::DIR . "notepad-utf-16le.vtt");
        $subtitle = Subtitle::parse($raw);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([1.0, 3.0, "Grüße aus Köln!"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([7.0, 9.0, "<b>Ende</b>"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame("Saved with Notepad", $subtitle->getComments()[0]["text"]);
        $this->assertEquals($subtitle, Subtitle::parse($raw, WebVttParser::class, "Windows-1252"));

        $output = $subtitle->format(WebVttFormatter::class, self::WINDOWS_OUTPUT);
        $again  = Subtitle::parse("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), WebVttParser::class);
        $this->assertSame($output, $again->format(WebVttFormatter::class, self::WINDOWS_OUTPUT));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues),
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $again->getCues())
        );
    }
}
