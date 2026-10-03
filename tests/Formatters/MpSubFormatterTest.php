<?php

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MpSubFormatterTest extends TestCase
{
    public function testSubtitleIsFormattedCorrectlyAndXmlTagsAreStripped()
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid.mpsub"),
            $subtitle->toString(Format::MpSub)
        );
    }


    public function testFrameRateOptionWritesFrames(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), Format::SubRip);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid_25fps.mpsub"),
            $subtitle->toString(Format::MpSub, [MpSubFormatter::OPTION_FRAME_RATE => 25])
        );
    }


    public function testFramesAreRoundedFromAbsoluteTimesSoErrorsDoNotAddUp(): void
    {
        $subtitle = new Subtitle();
        for ($i = 0; $i < 4; $i++) {
            $subtitle->addCue(new SubtitleCue($i * 0.06, ($i + 1) * 0.06, "Cue $i"));
        }

        $output = $subtitle->toString(Format::MpSub, [MpSubFormatter::OPTION_FRAME_RATE => 25]);

        $this->assertStringContainsString("\n0 2\nCue 0\n\n0 1\nCue 1\n\n0 2\nCue 2\n\n0 1\nCue 3\n", $output);
    }


    public function testEscapedCoreMarkupTextIsWrittenAsPlainText(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, ["<i>I &lt;3 bread &amp; jam</i>"]));

        $this->assertStringContainsString("\n1 1\nI <3 bread & jam\n", $subtitle->toString(Format::MpSub));
    }


    public function testNonIntegerFrameRateThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The MPSub frame rate must be a positive integer!");
        (new Subtitle())->toString(Format::MpSub, [MpSubFormatter::OPTION_FRAME_RATE => 23.976]);
    }


    public function testHeaderWithoutMetadataAndFormatDataIsUnchanged(): void
    {
        $subtitle = (new Subtitle())->setMetadata(Subtitle::METADATA_LANGUAGE, "en");

        $this->assertSame("\xEF\xBB\xBF" . MpSubFormatter::MPSUB_HEADER, $subtitle->toString(Format::MpSub));
    }


    public function testHeaderHoldsMetadataAndFormatData(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_TITLE, "Yesterday")
            ->setMetadata(Subtitle::METADATA_AUTHOR, "Jane Doe")
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "en")
            ->setFormatData("mpsub", ["NOTE" => "Draft", "FILE" => "123,abc", "FORMAT" => "30", "TYPE" => "AUDIO"]);

        $this->assertSame(
            "\xEF\xBB\xBFTITLE=Yesterday\nAUTHOR=Jane Doe\nTYPE=AUDIO\nFILE=123,abc\nFORMAT=TIME\nNOTE=Draft\n",
            $subtitle->toString(Format::MpSub)
        );
    }
}
