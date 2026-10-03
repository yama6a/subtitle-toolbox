<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

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
        $subtitle = (new MicroDvdParser())->parse($raw, new ReadOptions(fps: $frameRate));

        // Some source files have no line break after the last cue.
        $this->assertSame(
            rtrim($raw, "\n") . "\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: $frameRate)))
        );
    }


    public function testValidFileRoundTripsWithFrameRateLine(): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/microdvd/valid.sub");

        $this->assertSame($raw, Subtitle::fromString($raw, Format::MicroDvd)->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 23.976, writeFrameRateLine: true))));
    }


    public function testFrameRateOptionIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("MicroDVD output needs the frame rate of the video. Pass MicroDvdOptions::frameRate.");
        (new Subtitle())->addCue(new SubtitleCue(1, 2, "Hello"))->toString(Format::MicroDvd);
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
            $subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 25)))
        );
    }


    public function testSingleQuotedAndUnquotedColoursBecomeColourCodes(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "<font color='#ff0000'>Red</font>"))
            ->addCue(new SubtitleCue(3, 4, "<font color=#00ff00>Green</font>"));

        $this->assertSame(
            "{25}{50}{c:\$0000FF}Red\n{75}{100}{c:\$00FF00}Green\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 25)))
        );
    }


    public function testStripAllTagsOptionDropsStyleCodes(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, "<i>Hello</i>"));

        $this->assertSame("{25}{50}Hello\n", $subtitle->toString(Format::MicroDvd, new WriteOptions(stripTags: true, format: new MicroDvdOptions(frameRate: 25))));
    }


    public function testChangedStyleWritesNewCodesAndKeepsOtherCodes(): void
    {
        $subtitle = Subtitle::fromString("{1}{1}25\n{25}{50}{Y:i}{f:Arial}One|Two", Format::MicroDvd);
        $subtitle->getCues()[0]->setLines(["<b>One</b>", "<i>Two</i>"]);

        $this->assertSame(
            "{25}{50}{y:b}{f:Arial}One|{y:i}Two\n",
            $subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 25)))
        );
    }


    public function testSubRipConvertsToMicroDvd(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);
        $output   = $subtitle->toString(Format::MicroDvd, new WriteOptions(format: new MicroDvdOptions(frameRate: 25)));
        $reparsed = (new MicroDvdParser())->parse($output, new ReadOptions(fps: 25));

        $this->assertSame(count($subtitle->getCues()), count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $index => $cue) {
            $this->assertEqualsWithDelta($cue->getStart(), $reparsed->getCues()[$index]->getStart(), 0.02);
            $this->assertEqualsWithDelta($cue->getEnd(), $reparsed->getCues()[$index]->getEnd(), 0.02);
        }
    }
}
