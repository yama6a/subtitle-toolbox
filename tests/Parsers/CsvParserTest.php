<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\SubtitleCue;

class CsvParserTest extends TestCase
{
    private function describe(string $csv, ?CsvParser $parser = null): array
    {
        return array_map(
            fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()],
            array_values(($parser ?? new CsvParser())->parse($csv)->getCues())
        );
    }


    public function testReadsTheIssueExample(): void
    {
        $csv = "start,end,speaker,text\n" .
               "00:00:01.000,00:00:04.000,Anna,\"Where are you going?\"\n" .
               "00:00:04.500,00:00:06.000,Ben,\"Home.\nNow.\"\n";

        $this->assertSame([
            [1.0, 4.0, ["<v Anna>Where are you going?"]],
            [4.5, 6.0, ["<v Ben>Home.", "Now."]],
        ], $this->describe($csv));
    }


    public static function delimiters(): array
    {
        return [
            "comma"     => [","],
            "semicolon" => [";"],
            "tab"       => ["\t"],
        ];
    }


    #[DataProvider("delimiters")]
    public function testDetectsTheDelimiterFromTheHeaderLine(string $delimiter): void
    {
        $csv = implode($delimiter, ["Start", "End", "Text"]) . "\n" . implode($delimiter, ["1", "2", "\"a, b; c\""]) . "\n";

        $this->assertSame($delimiter, CsvParser::detectDelimiter($csv));
        $this->assertSame([[1.0, 2.0, ["a, b; c"]]], $this->describe($csv));
    }


    public function testTheDelimiterArgumentWinsOverDetection(): void
    {
        $this->assertSame([[1.0, 2.0, ["a,b"]]], $this->describe("start;end;text\n1;2;a,b\n", new CsvParser(delimiter: ";")));
        $this->assertSame([[1.0, 2.0, ["x;y"]]], $this->describe("start,end,text;more\n1,2,x;y\n", new CsvParser(new CsvColumns(text: "text;more"), ",")));
    }


    public function testReadsRfc4180Quoting(): void
    {
        $csv = "start,end,text\r\n1,2,\"She said \"\"hi\"\",\r\nthen left.\"\r\n3,4,plain \"quote\"\r\n";

        $this->assertSame([
            [1.0, 2.0, ["She said \"hi\",", "then left."]],
            [3.0, 4.0, ["plain \"quote\""]],
        ], $this->describe($csv));
    }


    public function testEscapesMarkupCharacters(): void
    {
        $this->assertSame([[1.0, 2.0, ["&lt;i&gt;Tom &amp; Ann&lt;/i&gt;"]]], $this->describe("start,end,text\n1,2,<i>Tom & Ann</i>\n"));
    }


    public static function times(): array
    {
        return [
            "seconds"           => ["62.5", 62.5],
            "whole seconds"     => ["62", 62.0],
            "dot"               => ["00:01:02.500", 62.5],
            "comma"             => ["\"00:01:02,500\"", 62.5],
            "one hour digit"    => ["1:00:00.25", 3600.25],
            "frames at 25 fps"  => ["00:01:02:12", 62.48],
        ];
    }


    #[DataProvider("times")]
    public function testReadsTimeFormats(string $time, float $seconds): void
    {
        $cues = $this->describe("start,text\n$time,a\n", new CsvParser(new CsvColumns(frameRate: 25)));

        $this->assertSame($seconds, $cues[0][0]);
    }


    public function testFramesNeedAFrameRate(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"00:00:01:12\" counts frames. Pass the frame rate in CsvColumns. (line 2)");

        (new CsvParser())->parse("start,end,text\n00:00:01:12,00:00:02:00,a\n");
    }


    public function testABadTimeNamesTheLine(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("(line 4)");

        (new CsvParser())->parse("start,end,text\n1,2,\"a\nb\"\nsoon,3,c\n");
    }


    public function testLenientModeSkipsABadRow(): void
    {
        $parser = (new CsvParser())->setLenient();

        $this->assertSame([[3.0, 4.0, ["b"]]], $this->describe("start,end,text\nsoon,2,a\n3,4,b\n", $parser));
        $this->assertCount(1, $parser->getWarnings());
        $this->assertSame(2, $parser->getWarnings()[0]->lineNumber);
        $this->assertSame(ParseWarning::SKIPPED, $parser->getWarnings()[0]->action);
    }


    public function testWithoutEndACueEndsAtTheNextStart(): void
    {
        $this->assertSame([
            [1.0, 4.0, ["b"]],
            [1.0, 4.0, ["a"]],
            [4.0, 6.0, ["c"]],
        ], $this->describe("text,start\nb,1\na,1\nc,4\n", new CsvParser(lastCueDuration: 2)));
    }


    public function testAnEmptyEndCellEndsAtTheNextStart(): void
    {
        $this->assertSame([[1.0, 3.0, ["a"]], [3.0, 5.0, ["b"]]], $this->describe("start,end,text\n1,,a\n3,5,b\n"));
    }


    public function testReadsADurationColumn(): void
    {
        $this->assertSame([[1.5, 3.5, ["a"]]], $this->describe("Start,Duration,Text\n1.5,2,a\n"));
    }


    public function testMapsColumnsByIndexWithoutAHeader(): void
    {
        $parser = new CsvParser(new CsvColumns(start: 1, end: 2, text: 0, header: false));

        $this->assertSame([[1.0, 2.0, ["a"]], [3.0, 4.0, ["b"]]], $this->describe("a,1,2\nb,3,4\n", $parser));
    }


    public function testKeepsOtherColumnsAndIdentifiers(): void
    {
        $subtitle = (new CsvParser(new CsvColumns(identifier: "id")))->parse("ID,Start,End,Text,Take\nshot-1,1,2,a,3\n\n");
        $cue      = $subtitle->getCues()[0];

        $this->assertSame("shot-1", $cue->getIdentifier());
        $this->assertSame(["columns" => ["Take" => "3"]], $cue->getFormatData("csv"));
        $this->assertSame([
            "delimiter"  => ",",
            "header"     => ["ID", "Start", "End", "Text", "Take"],
            "roles"      => ["identifier" => 0, "start" => 1, "end" => 2, "text" => 3],
            "width"      => 5,
            "timeFormat" => CsvParser::TIME_SECONDS,
            "frameRate"  => null,
        ], $subtitle->getFormatData("csv"));
    }


    public function testIdentifierHeaderMapsToTheCueIdentifier(): void
    {
        $this->assertSame("intro", (new CsvParser())->parse("identifier,start,text\nintro,1,a\n")->getCues()[0]->getIdentifier());
    }


    public function testSkipsEmptyRowsAndReadsAUtf8Bom(): void
    {
        $this->assertSame([[1.0, 2.0, ["a"]]], $this->describe("\xEF\xBB\xBFstart,end,text\n\n,,\n1,2,a"));
    }


    public function testAMissingColumnThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"Character\" for speaker.");

        (new CsvParser(new CsvColumns(speaker: "Character")))->parse("start,end,text\n1,2,a\n");
    }


    public function testATableWithoutTextThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The table has no column \"text\" for text.");

        (new CsvParser())->parse("start,end\n1,2\n");
    }


    public function testAnOpenQuoteThrows(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("A quoted CSV cell has no closing quote. (line 2)");

        (new CsvParser())->parse("start,end,text\n1,2,\"open\n");
    }


    public function testRejectsAnUnknownDelimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CsvParser(delimiter: "|");
    }


    public function testColumnsWithoutAHeaderNeedIndexes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A table without a header row needs a column index for text.");

        new CsvColumns(start: 0, header: false);
    }
}
