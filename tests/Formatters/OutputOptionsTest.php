<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class OutputOptionsTest extends TestCase
{
    public static function formatters(): array
    {
        return [
            "ASS"      => [AssFormatter::class, [], true],
            "LRC"      => [LyricsFormatter::class, [], true],
            "MicroDVD" => [MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25], false],
            "MPSub"    => [MpSubFormatter::class, [], true],
            "SAMI"     => [SamiFormatter::class, [], false],
            "SBV"      => [SbvFormatter::class, [], false],
            "SubRip"   => [SubRipFormatter::class, [], true],
            "TTML"     => [TtmlFormatter::class, [], false],
            "WebVTT"   => [WebVttFormatter::class, [], true],
        ];
    }


    #[DataProvider("formatters")]
    public function testDefaultsKeepLfAndTheBomOfTheFormat(string $formatter, array $options, bool $writesBom): void
    {
        $output = self::subtitle()->format($formatter, $options);

        $this->assertSame($writesBom, StringHelpers::hasUtf8Bom($output));
        $this->assertStringNotContainsString("\r", $output);
        $this->assertSame($output, self::subtitle()->format($formatter, $options + [SubtitleFormatter::OPTION_LINE_ENDING => "\n"]));
    }


    #[DataProvider("formatters")]
    public function testCrLfReplacesEveryLineEnding(string $formatter, array $options): void
    {
        $default = self::subtitle()->format($formatter, $options);
        $output  = self::subtitle()->format($formatter, $options + [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"]);

        $this->assertSame(str_replace("\n", "\r\n", $default), $output);
        $this->assertSame(substr_count($default, "\n"), substr_count($output, "\r\n"));
    }


    #[DataProvider("formatters")]
    public function testBomOptionAddsOrRemovesTheUtf8Bom(string $formatter, array $options): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle()->format($formatter, $options));

        $this->assertSame("\xEF\xBB\xBF" . $default, self::subtitle()->format($formatter, $options + [SubtitleFormatter::OPTION_BOM => true]));
        $this->assertSame($default, self::subtitle()->format($formatter, $options + [SubtitleFormatter::OPTION_BOM => false]));
    }


    #[DataProvider("formatters")]
    public function testBothOptionsWorkTogether(string $formatter, array $options): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle()->format($formatter, $options));
        $output  = self::subtitle()->format($formatter, $options + [
            SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
            SubtitleFormatter::OPTION_BOM         => true,
        ]);

        $this->assertSame("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $default), $output);
    }


    #[DataProvider("formatters")]
    public function testFormatterCalledDirectlyAppliesTheOptions(string $formatter, array $options): void
    {
        $output = (new $formatter())->format(self::subtitle(), $options + [
            SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
            SubtitleFormatter::OPTION_BOM         => false,
        ]);

        $this->assertFalse(StringHelpers::hasUtf8Bom($output));
        $this->assertStringContainsString("\r\n", $output);
        $this->assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $output);
    }


    public function testUnknownLineEndingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::subtitle()->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_LINE_ENDING => "\r"]);
    }


    public function testBomOptionThatIsNotABooleanThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::subtitle()->format(SubRipFormatter::class, [SubtitleFormatter::OPTION_BOM => "yes"]);
    }


    private static function subtitle(): Subtitle
    {
        return (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Café")
            ->addComment("Note", 1)
            ->addCue(new SubtitleCue(1.5, 4, "<i>Bonjour</i>\nça va ?"))
            ->addCue(new SubtitleCue(5, 7.25, "Second cue"));
    }
}
