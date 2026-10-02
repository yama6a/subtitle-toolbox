<?php

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MicroDvdFormatterTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            ["sub_microdvd.sub", 23.976],
            ["sub_microdvd_with_styles.sub", 23.976],
            ["subsrt_sample.sub", 25],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileRoundTrips(string $file, float $frameRate): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/microdvd/real/$file");
        $subtitle = (new MicroDvdParser($frameRate))->parse($raw);

        // Some source files have no line break after the last cue.
        $this->assertSame(
            rtrim($raw, "\n") . "\n",
            $subtitle->format(MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => $frameRate])
        );
    }


    public function testValidFileRoundTripsWithFrameRateLine(): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/microdvd/valid.sub");

        $this->assertSame($raw, Subtitle::parse($raw, MicroDvdParser::class)->format(MicroDvdFormatter::class, [
            MicroDvdFormatter::OPTION_FRAME_RATE            => 23.976,
            MicroDvdFormatter::OPTION_WRITE_FRAME_RATE_LINE => true,
        ]));
    }


    public function testFrameRateOptionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("OPTION_FRAME_RATE");
        (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->format(MicroDvdFormatter::class);
    }


    public function testCoreMarkupBecomesControlCodes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, [
            "<font color=\"#ff0000\"><b><i>Red</i></b></font>",
            "<u><s>Under &amp; strike</s></u>",
            "Partly <i>italic</i> &lt;3",
        ]));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}{y:b}{y:i}Red|{y:u}{y:s}Under & strike|Partly italic <3\n",
            $subtitle->format(MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25])
        );
    }


    public function testStripAllTagsOptionDropsStyleCodes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<i>Hello</i>"));

        $this->assertSame("{25}{50}Hello\n", $subtitle->format(MicroDvdFormatter::class, [
            MicroDvdFormatter::OPTION_FRAME_RATE => 25,
            SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS,
        ]));
    }


    public function testChangedStyleWritesNewCodesAndKeepsOtherCodes(): void
    {
        $subtitle = Subtitle::parse("{1}{1}25\n{25}{50}{Y:i}{f:Arial}One|Two", MicroDvdParser::class);
        $subtitle->getCues()[0]->setLines(["<b>One</b>", "<i>Two</i>"]);

        $this->assertSame(
            "{25}{50}{y:b}{f:Arial}One|{y:i}Two\n",
            $subtitle->format(MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25])
        );
    }


    public function testSubRipConvertsToMicroDvd(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), SubRipParser::class);
        $output   = $subtitle->format(MicroDvdFormatter::class, [MicroDvdFormatter::OPTION_FRAME_RATE => 25]);
        $reparsed = (new MicroDvdParser(25))->parse($output);

        $this->assertSame(count($subtitle->getCues()), count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $index => $cue) {
            $this->assertEqualsWithDelta($cue->getStart(), $reparsed->getCues()[$index]->getStart(), 0.02);
            $this->assertEqualsWithDelta($cue->getEnd(), $reparsed->getCues()[$index]->getEnd(), 0.02);
        }
    }
}
