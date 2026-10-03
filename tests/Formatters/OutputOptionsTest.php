<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class OutputOptionsTest extends TestCase
{
    public static function formatters(): array
    {
        return [
            "ASS"      => [Format::Ass, [], true],
            "FFmpeg metadata"  => [Format::FfMetadata, [], false],
            "LRC"      => [Format::Lyrics, [], true],
            "MicroDVD" => [Format::MicroDvd, [MicroDvdFormatter::OPTION_FRAME_RATE => 25], false],
            "MPSub"    => [Format::MpSub, [], true],
            "OGM chapters"     => [Format::OgmChapters, [], false],
            "Podcast chapters" => [Format::PodcastChapters, [], false],
            "SAMI"     => [Format::Sami, [], false],
            "SBV"      => [Format::Sbv, [], false],
            "SubRip"   => [Format::SubRip, [], true],
            "TTML"     => [Format::Ttml, [], false],
            "WebVTT"   => [Format::WebVtt, [], true],
            "YouTube chapters" => [Format::YouTubeChapters, [], false],
        ];
    }


    #[DataProvider("formatters")]
    public function testDefaultsKeepLfAndTheBomOfTheFormat(Format $format, array $options, bool $writesBom): void
    {
        $output = self::subtitle()->toString($format, $options);

        $this->assertSame($writesBom, StringHelpers::hasUtf8Bom($output));
        $this->assertStringNotContainsString("\r", $output);
        $this->assertSame($output, self::subtitle()->toString($format, $options + [SubtitleFormatter::OPTION_LINE_ENDING => "\n"]));
    }


    #[DataProvider("formatters")]
    public function testCrLfReplacesEveryLineEnding(Format $format, array $options): void
    {
        $default = self::subtitle()->toString($format, $options);
        $output  = self::subtitle()->toString($format, $options + [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"]);

        $this->assertSame(str_replace("\n", "\r\n", $default), $output);
        $this->assertSame(substr_count($default, "\n"), substr_count($output, "\r\n"));
    }


    #[DataProvider("formatters")]
    public function testBomOptionAddsOrRemovesTheUtf8Bom(Format $format, array $options): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle()->toString($format, $options));

        $this->assertSame("\xEF\xBB\xBF" . $default, self::subtitle()->toString($format, $options + [SubtitleFormatter::OPTION_BOM => true]));
        $this->assertSame($default, self::subtitle()->toString($format, $options + [SubtitleFormatter::OPTION_BOM => false]));
    }


    #[DataProvider("formatters")]
    public function testBothOptionsWorkTogether(Format $format, array $options): void
    {
        $default = StringHelpers::removeUtf8Bom(self::subtitle()->toString($format, $options));
        $output  = self::subtitle()->toString($format, $options + [
            SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
            SubtitleFormatter::OPTION_BOM         => true,
        ]);

        $this->assertSame("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $default), $output);
    }


    #[DataProvider("formatters")]
    public function testFormatterCalledDirectlyAppliesTheOptions(Format $format, array $options): void
    {
        $output = (new (FormatRegistry::formatterClass($format))())->format(self::subtitle(), $options + [
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

        self::subtitle()->toString(Format::SubRip, [SubtitleFormatter::OPTION_LINE_ENDING => "\r"]);
    }


    public function testBomOptionThatIsNotABooleanThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::subtitle()->toString(Format::SubRip, [SubtitleFormatter::OPTION_BOM => "yes"]);
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
