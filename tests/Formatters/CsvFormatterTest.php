<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Parsers\CsvColumns;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class CsvFormatterTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";


    private static function english(): Subtitle
    {
        return (new Subtitle())
            ->addCue(new SubtitleCue(1, 4, "<v Anna>Where are you going?"))
            ->addCue(new SubtitleCue(4.5, 6, ["<v Ben><i>Home.</i>", "Now."]));
    }


    public function testWritesTheIssueExampleWithABom(): void
    {
        $this->assertSame(
            self::BOM . "start,end,speaker,text\n" .
            "00:00:01.000,00:00:04.000,Anna,Where are you going?\n" .
            "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\"\n",
            self::english()->format(CsvFormatter::class)
        );
    }


    public function testQuotesCellsAndDecodesEntities(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "Tom &amp; \"Ann\", <b>both</b>"));

        $this->assertSame(
            "start,end,text\n00:00:01.000,00:00:02.000,\"Tom & \"\"Ann\"\", both\"\n",
            $subtitle->format(CsvFormatter::class, [SubtitleFormatter::OPTION_BOM => false])
        );
    }


    public function testWritesTheDelimiterAndTheLineEnding(): void
    {
        $this->assertSame(
            "start;end;speaker;text\r\n00:00:01.000;00:00:04.000;Anna;Where are you going?\r\n" .
            "00:00:04.500;00:00:06.000;Ben;\"Home.\nNow.\"\r\n",
            self::english()->format(CsvFormatter::class, [
                CsvFormatter::OPTION_DELIMITER        => ";",
                SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
                SubtitleFormatter::OPTION_BOM         => false,
            ])
        );
    }


    public static function timeFormats(): array
    {
        return [
            "seconds" => [CsvParser::TIME_SECONDS, "62.48", "3599.99"],
            "dot"     => [CsvParser::TIME_DOT, "00:01:02.480", "00:59:59.990"],
            "comma"   => [CsvParser::TIME_COMMA, "\"00:01:02,480\"", "\"00:59:59,990\""],
            "frames"  => [CsvParser::TIME_FRAMES, "00:01:02:12", "01:00:00:00"],
        ];
    }


    #[DataProvider("timeFormats")]
    public function testWritesTimeFormats(string $timeFormat, string $start, string $end): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(62.48, 3599.99, "a"));

        $this->assertSame("start,end,text\n$start,$end,a\n", $subtitle->format(CsvFormatter::class, [
            CsvFormatter::OPTION_TIME_FORMAT => $timeFormat,
            CsvFormatter::OPTION_FRAME_RATE  => 25,
            SubtitleFormatter::OPTION_BOM    => false,
        ]));
    }


    public function testFrameRateKeyOfTheOtherFormattersWinsOverTheParsedFrameRate(): void
    {
        $file     = file_get_contents(__DIR__ . "/../files/csv/real/dubbing_script.csv");
        $parser   = new CsvParser(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25));
        $subtitle = $parser->parse($file);

        $shared = $subtitle->format(CsvFormatter::class, [EbuStlFormatter::OPTION_FRAME_RATE => 24]);

        $this->assertSame("OPTION_FRAME_RATE", CsvFormatter::OPTION_FRAME_RATE);
        $this->assertStringContainsString("\n10:00:05:19,BEN,- I have one.,\n", $shared);
        $this->assertStringContainsString("\n10:00:05:20,BEN,- I have one.,\n", $subtitle->format(CsvFormatter::class));
    }


    public function testOldFrameRateKeyStillWorks(): void
    {
        $file     = file_get_contents(__DIR__ . "/../files/csv/real/dubbing_script.csv");
        $parser   = new CsvParser(new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25));
        $subtitle = $parser->parse($file);

        $this->assertSame(
            $subtitle->format(CsvFormatter::class, [CsvFormatter::OPTION_FRAME_RATE => 24]),
            $subtitle->format(CsvFormatter::class, ["frameRate" => 24])
        );
        $this->assertSame(
            $subtitle->format(CsvFormatter::class, [CsvFormatter::OPTION_FRAME_RATE => 30]),
            $subtitle->format(CsvFormatter::class, [CsvFormatter::OPTION_FRAME_RATE => 30, "frameRate" => 24])
        );
    }


    public function testWritesIdentifiersAndOtherColumnsFromAnotherFormat(): void
    {
        $subtitle = Subtitle::parse("WEBVTT\n\nintro\n00:00:01.000 --> 00:00:02.000\nHi\n", null);
        $subtitle->getCues()[0]->setFormatData("csv", ["columns" => ["Take" => "2"]]);

        $this->assertSame("identifier,start,end,text,Take\nintro,00:00:01.000,00:00:02.000,Hi,2\n", $subtitle->format(CsvFormatter::class, [
            SubtitleFormatter::OPTION_BOM => false,
        ]));
    }


    public function testAddsASpeakerColumnToAParsedTableWithoutOne(): void
    {
        $subtitle = (new CsvParser())->parse("Start;Text\n1;a\n");
        $subtitle->getCues()[0]->setLines("<v Lena>a");

        $this->assertSame("Start;speaker;Text\n1;Lena;a\n", $subtitle->format(CsvFormatter::class, [SubtitleFormatter::OPTION_BOM => false]));
    }


    public function testWritesATableWithoutAHeaderRowBack(): void
    {
        $content  = "a\t1\t2\textra\nb\t3\t4\t\n";
        $subtitle = (new CsvParser(new CsvColumns(start: 1, end: 2, text: 0, header: false)))->parse($content);

        $this->assertSame($content, $subtitle->format(CsvFormatter::class, [SubtitleFormatter::OPTION_BOM => false]));
    }


    public function testKeepsAHeaderColumnThatNoRowFills(): void
    {
        $content = "start,end,text,Notes\n1,2,a\n";

        $this->assertSame("start,end,text,Notes\n1,2,a,\n", Subtitle::parse($content, CsvParser::class)->format(CsvFormatter::class, [
            SubtitleFormatter::OPTION_BOM => false,
        ]));
    }


    public function testWritesABilingualTable(): void
    {
        $german = Subtitle::parse(
            "1\n00:00:01,200 --> 00:00:03,900\nWohin gehst du?\n\n" .
            "2\n00:00:04,400 --> 00:00:09,000\nNach Hause. Jetzt.\n",
            SubRipParser::class
        );
        $english = self::english()->addCue(new SubtitleCue(6.5, 8, "Right now."));

        $this->assertSame(
            "start,end,speaker,text,text (de)\n" .
            "00:00:01.000,00:00:04.000,Anna,Where are you going?,Wohin gehst du?\n" .
            "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\",Nach Hause. Jetzt.\n" .
            "00:00:06.500,00:00:08.000,,Right now.,\n",
            $english->format(CsvFormatter::class, [
                CsvFormatter::OPTION_SECOND_TEXT        => $german,
                CsvFormatter::OPTION_SECOND_TEXT_HEADER => "text (de)",
                SubtitleFormatter::OPTION_BOM           => false,
            ])
        );
    }


    public function testASecondCueOverRowsFillsTheFirstRowItOverlapsMost(): void
    {
        $primary = (new Subtitle())->addCue(new SubtitleCue(0, 2, "a"))->addCue(new SubtitleCue(2, 6, "b"));
        $second  = (new Subtitle())->addCue(new SubtitleCue(1, 6, "x"));

        $this->assertSame(
            "start,end,text,text2\n0,2,a,x\n2,6,b,\n",
            $primary->format(CsvFormatter::class, [
                CsvFormatter::OPTION_SECOND_TEXT => $second,
                CsvFormatter::OPTION_TIME_FORMAT => CsvParser::TIME_SECONDS,
                SubtitleFormatter::OPTION_BOM    => false,
            ])
        );
    }


    public function testEscapesFormulasOnlyWhenAsked(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, ["- Hi.", "- Hello."]))
            ->addCue(new SubtitleCue(3, 4, "=SUM(A1)"))
            ->addCue(new SubtitleCue(5, 6, "+1 @home"));
        $options = [CsvFormatter::OPTION_TIME_FORMAT => CsvParser::TIME_SECONDS, SubtitleFormatter::OPTION_BOM => false];

        $this->assertSame(
            "start,end,text\n1,2,\"- Hi.\n- Hello.\"\n3,4,=SUM(A1)\n5,6,+1 @home\n",
            $subtitle->format(CsvFormatter::class, $options)
        );
        $this->assertSame(
            "start,end,text\n1,2,\"'- Hi.\n- Hello.\"\n3,4,'=SUM(A1)\n5,6,'+1 @home\n",
            $subtitle->format(CsvFormatter::class, [CsvFormatter::OPTION_ESCAPE_FORMULAS => true] + $options)
        );
    }


    public function testRoundTripsThroughTheParser(): void
    {
        $csv = self::english()->format(CsvFormatter::class);

        $this->assertSame($csv, Subtitle::parse($csv, CsvParser::class)->format(CsvFormatter::class));
    }


    public static function invalidOptions(): array
    {
        return [
            "delimiter"           => [[CsvFormatter::OPTION_DELIMITER => "|"]],
            "time format"         => [[CsvFormatter::OPTION_TIME_FORMAT => "mm:ss"]],
            "frames without rate" => [[CsvFormatter::OPTION_TIME_FORMAT => CsvParser::TIME_FRAMES]],
            "second text"         => [[CsvFormatter::OPTION_SECOND_TEXT => "text"]],
            "line ending"         => [[SubtitleFormatter::OPTION_LINE_ENDING => "\r"]],
        ];
    }


    #[DataProvider("invalidOptions")]
    public function testRejectsInvalidOptions(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::english()->format(CsvFormatter::class, $options);
    }
}
