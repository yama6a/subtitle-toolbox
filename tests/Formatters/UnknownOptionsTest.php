<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

class UnknownOptionsTest extends TestCase
{
    private const FILE = __DIR__ . "/../files/srt/real/own_styled.srt";

    private const REQUIRED = [
        IttFormatter::class      => [IttFormatter::OPTION_FRAME_RATE => 25],
        MicroDvdFormatter::class => [MicroDvdFormatter::OPTION_FRAME_RATE => 25],
    ];


    private static function subtitle(): Subtitle
    {
        return Subtitle::parse(file_get_contents(self::FILE), SubRipParser::class);
    }


    public static function formatters(): array
    {
        $formatters = [];
        foreach (array_unique(FormatRegistry::formatterClasses()) as $formatter) {
            $formatters[substr(strrchr($formatter, "\\"), 1)] = [$formatter];
        }

        return $formatters;
    }


    #[DataProvider("formatters")]
    public function testMisspelledKeyThrows(string $formatter): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not know the option \"lineEndings\".");

        self::subtitle()->format($formatter, (self::REQUIRED[$formatter] ?? []) + ["lineEndings" => "\r\n"]);
    }


    public function testKeyOfAnotherFormatterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SubRipFormatter does not know the option \"OPTION_FRAME_RATE\". It knows the options " .
                                      "OPTION_STRIP_ALL_XML_TAGS, lineEnding, bom, skipImageCues.");

        self::subtitle()->format(SubRipFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25]);
    }


    public function testDirectCallThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WebVttFormatter())->format(self::subtitle(), ["bomb" => false]);
    }


    public function testDocumentedOptionsStillWork(): void
    {
        $subtitle = self::subtitle();

        $this->assertStringStartsWith("1\r\n00:00:17,985 --> 00:00:20,521\r\n[train horn]\r\n", $subtitle->format(SubRipFormatter::class, [
            SubtitleFormatter::OPTION_LINE_ENDING     => "\r\n",
            SubtitleFormatter::OPTION_BOM             => false,
            SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true,
            SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS,
        ]));
        $this->assertStringContainsString(">[train horn]</p>", $subtitle->format(IttFormatter::class, [
            IttFormatter::OPTION_FRAME_RATE => 23.976,
            SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS,
        ]));
        $this->assertStringStartsWith("{1}{1}25\n", $subtitle->format(MicroDvdFormatter::class, [
            MicroDvdFormatter::OPTION_FRAME_RATE            => 25,
            MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE => true,
        ]));
        $this->assertStringStartsWith("start;end;text;text (de)\n17.985;20.521;[train horn];[train horn]\n", $subtitle->format(CsvFormatter::class, [
            CsvFormatter::OPTION_DELIMITER          => ";",
            CsvFormatter::OPTION_TIME_FORMAT        => "seconds",
            CsvFormatter::OPTION_SECOND_TEXT        => $subtitle,
            CsvFormatter::OPTION_SECOND_TEXT_HEADER => "text (de)",
            CsvFormatter::OPTION_ESCAPE_FORMULAS    => true,
            SubtitleFormatter::OPTION_BOM           => false,
        ]));
    }


    public function testFormatterOutsideTheLibraryKeepsItsOwnKeys(): void
    {
        $formatter = new class extends SubtitleFormatter {
            public function format(Subtitle $subtitle, array $options = []): string
            {
                return $this->applyOutputOptions($options["prefix"] . count($subtitle->getCues()), $options);
            }
        };

        $this->assertSame("cues: 10", $formatter->format(self::subtitle(), ["prefix" => "cues: "]));
    }
}
