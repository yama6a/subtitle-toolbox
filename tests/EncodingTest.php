<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;

class EncodingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/encoding/";

    private static function windowsOutput(): WriteOptions
    {
        return new WriteOptions(lineEnding: LineEnding::Crlf, bom: false);
    }


    public static function legacyFiles(): array
    {
        return [
            "French Windows-1252"  => [
                "french-windows-1252.srt", "Windows-1252", Format::SubRip,
                [1.0, 3.5, "Le café est fermé à midi."],
                [70.1, 72.9, "<i>Où êtes-vous, Noël ?</i>"],
            ],
            "Russian Windows-1251" => [
                "russian-windows-1251.srt", "Windows-1251", Format::SubRip,
                [2.0, 4.0, "Привет, как дела?"],
                [9.0, 11.5, "Ёлка стоит в углу."],
            ],
            "Japanese Shift_JIS"   => [
                "japanese-shift_jis.srt", "Shift_JIS", Format::SubRip,
                [1.2, 3.0, "こんにちは、元気ですか？"],
                [7.0, 9.8, "駅まで歩きましょう。"],
            ],
            "Korean CP949 SAMI"    => [
                "korean-cp949.smi", "CP949", Format::Sami,
                [1.0, 3.5, "안녕하세요.\n기차가 곧 도착합니다."],
                [7.0, 9.0, "<font color=\"#ffff00\">감사합니다.</font>"],
            ],
        ];
    }


    public function testUtf8FileStaysIntactWithAnotherSourceEncoding(): void
    {
        $options = new ReadOptions(encoding: TextEncoding::Windows1256);
        $utf8    = Subtitle::load(self::DIR . "arabic-utf-8.srt", Format::SubRip, $options);
        $legacy  = Subtitle::load(self::DIR . "arabic-windows-1256.srt", Format::SubRip, $options);

        $this->assertSame("مرحبا", $utf8->getCues()[0]->getText());
        $this->assertSame("شكرا جزيلا.", $utf8->getCues()[2]->getText());
        $this->assertSame($utf8->toString(Format::SubRip), $legacy->toString(Format::SubRip));
    }


    #[DataProvider("legacyFiles")]
    public function testLegacyFileParsesWithItsSourceEncoding(
        string $file, string $encoding, Format $format, array $first, array $last
    ): void {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::fromString($raw, $format, new ReadOptions(encoding: $encoding));
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($last, [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame($raw, iconv("UTF-8", $encoding, $subtitle->toString($format, self::windowsOutput())));
    }


    #[DataProvider("legacyFiles")]
    public function testFormatDetectionWorksOnTheConvertedContent(string $file, string $encoding, Format $format): void
    {
        $detected = Subtitle::fromStringAutoDetectFormat(file_get_contents(self::DIR . $file), new ReadOptions(encoding: $encoding));

        $this->assertEquals(Subtitle::fromString(file_get_contents(self::DIR . $file), $format, new ReadOptions(encoding: $encoding)), $detected);
    }


    public function testCp949SamiOnlyParsesWithTheSourceEncoding(): void
    {
        $raw = file_get_contents(self::DIR . "korean-cp949.smi");

        $this->assertSame("기차 안내", Subtitle::fromString($raw, Format::Sami, new ReadOptions(encoding: "CP949"))->findMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("똠방각하가 왔습니다.", Subtitle::fromString($raw, Format::Sami, new ReadOptions(encoding: "CP949"))->getCues()[1]->getText());

        $this->expectException(ParsingException::class);
        Subtitle::fromString($raw, Format::Sami);
    }


    public function testWithoutSourceEncodingInvalidUtf8BytesStayAsTheyAre(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "french-windows-1252.srt"), Format::SubRip);

        $this->assertSame("Le caf\xE9 est ferm\xE9 \xE0 midi.", $subtitle->getCues()[0]->getText());
    }


    public function testUtf16LeFileWithBomParsesWithoutSourceEncoding(): void
    {
        $raw      = file_get_contents(self::DIR . "notepad-utf-16le.vtt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($raw);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([1.0, 3.0, "Grüße aus Köln!"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([7.0, 9.0, "<b>Ende</b>"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame("Saved with Notepad", $subtitle->getComments()[0]->text);
        $this->assertEquals($subtitle, Subtitle::fromString($raw, Format::WebVtt, new ReadOptions(encoding: "Windows-1252")));

        $output = $subtitle->toString(Format::WebVtt, self::windowsOutput());
        $again  = Subtitle::fromString("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), Format::WebVtt);
        $this->assertSame($output, $again->toString(Format::WebVtt, self::windowsOutput()));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues),
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $again->getCues())
        );
    }


    public static function utf16FilesWithoutBom(): array
    {
        $files = [];
        foreach (["UTF-16LE", "UTF-16BE"] as $encoding) {
            foreach (["srt" => Format::SubRip, "vtt" => Format::WebVtt, "sbv" => Format::Sbv, "ass" => Format::Ass,
                         "smi" => Format::Sami, "ttml" => Format::Ttml, "sub" => Format::MicroDvd, "csv" => Format::Csv] as $extension => $format) {
                foreach ([false, true] as $lenient) {
                    $name         = strtolower($encoding) . "-no-bom.$extension";
                    $mode         = $lenient ? "lenient" : "strict";
                    $files["$name, $mode"] = [$name, $encoding, $format, $lenient];
                }
            }
        }

        return $files;
    }


    #[DataProvider("utf16FilesWithoutBom")]
    public function testUtf16FileWithoutBomParses(string $file, string $encoding, Format $format, bool $lenient): void
    {
        $path    = self::DIR . "utf-16-no-bom/$file";
        $options = new ReadOptions(lenient: $lenient);
        $loaded  = [Subtitle::load($path, $format, $options), Subtitle::fromString(file_get_contents($path), $format, $options)];
        $loaded[] = Subtitle::loadAutoDetectFormat($path, $options);
        if ($format !== Format::Csv) {
            $loaded[] = Subtitle::fromStringAutoDetectFormat(file_get_contents($path), $options);
        }

        foreach ($loaded as $subtitle) {
            $this->assertSame($format, $subtitle->getFormat());
            $this->assertSame(
                [[1.0, 3.0, "The café opens at nine."], [4.0, 6.5, "Grüße from the harbor."]],
                array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $subtitle->getCues())
            );
            $warnings = array_map(fn (ParseWarning $warning) => [$warning->message, $warning->action], $subtitle->getParseWarnings());
            $this->assertSame($lenient ? [["The content is $encoding without a BOM.", ParseWarningAction::Repaired]] : [], $warnings);
        }
    }


    public function testUtf16WithoutBomKeepsAnExplicitUtf16SourceEncoding(): void
    {
        $raw = file_get_contents(self::DIR . "utf-16-no-bom/utf-16le-no-bom.srt");

        $this->assertSame("The café opens at nine.", Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: "UTF-16LE"))->getCues()[0]->getText());
        $this->assertSame("The café opens at nine.", Subtitle::fromString($raw, Format::SubRip, new ReadOptions(encoding: "Windows-1252"))->getCues()[0]->getText());
    }


    public static function xmlFilesThatDeclareUtf16(): array
    {
        return [
            "TTML, UTF-8 bytes"           => ["utf-8-declared-utf-16.ttml", Format::Ttml, [1.0, 2.5, "Le café ouvre à midi."]],
            "TTML, UTF-16 LE with BOM"    => ["utf-16le-bom.ttml", Format::Ttml, [1.0, 2.5, "Le café ouvre à midi."]],
            "iTT, UTF-8 bytes"            => ["utf-8-declared-utf-16.ttml", Format::Itt, [1.0, 2.5, "Le café ouvre à midi."]],
            "YouTube, UTF-16 LE with BOM" => ["youtube-utf-16le-bom.srv1", Format::YouTubeTimedText, [0.5, 2.5, "café on the corner"]],
        ];
    }


    #[DataProvider("xmlFilesThatDeclareUtf16")]
    public function testXmlThatDeclaresUtf16Parses(string $file, Format $format, array $firstCue): void
    {
        $cue = Subtitle::fromString(file_get_contents(self::DIR . $file), $format)->getCues()[0];

        $this->assertSame($firstCue, [$cue->getStart(), $cue->getEnd(), $cue->getText()]);
    }


    public function testUtf16TtmlRoundTrips(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "utf-16le-bom.ttml"), Format::Ttml);
        $output   = $subtitle->toString(Format::Ttml);
        $again    = Subtitle::fromString("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), Format::Ttml);

        $this->assertSame(["Le café ouvre à midi.", "À bientôt."], array_map(fn (SubtitleCue $cue) => $cue->getText(), $again->getCues()));
        $this->assertSame($output, $again->toString(Format::Ttml));
    }
}
