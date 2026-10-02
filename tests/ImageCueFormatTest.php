<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Formatters\ImageFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\FakeOcrEngine;

require_once __DIR__ . "/Ocr/FakeOcrEngine.php";

class ImageCueFormatTest extends TestCase
{
    private function addImageCue(Subtitle $subtitle, float $start, float $end): Subtitle
    {
        $image = new CueImage("png", 0, 0, 1, 1, 720, 576);

        return $subtitle->addCue($image->toCue(new SubtitleCue($start, $end)));
    }


    private function makeSubtitle(): Subtitle
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "First"))
            ->addCue(new SubtitleCue(5, 6, "Last"));
        $this->addImageCue($subtitle, 3, 4);

        return $subtitle->addComment("Before the image", 1)->addComment("Before the last cue", 2);
    }


    public function testTextFormatterThrowsOnImageCueWithoutText(): void
    {
        $this->expectException(ImageCueWithoutTextException::class);
        $this->expectExceptionMessage("ImageCueWithoutTextException (Error #103): Cue #1 holds an image but no text.");

        $this->makeSubtitle()->format(SubRipFormatter::class);
    }


    public function testSkipOptionDropsImageCuesWithoutTextAndKeepsTheSubtitle(): void
    {
        $subtitle = $this->makeSubtitle();

        $output = $subtitle->format(WebVttFormatter::class, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true]);

        $expected = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "First"))
            ->addCue(new SubtitleCue(5, 6, "Last"))
            ->addComment("Before the image", 1)
            ->addComment("Before the last cue", 1)
            ->format(WebVttFormatter::class);
        $this->assertSame($expected, $output);
        $this->assertCount(3, $subtitle->getCues());
        $this->assertSame(2, $subtitle->getComments()[1]["beforeCueIndex"]);
    }


    public function testImageCueWithTextIsFormattedAsText(): void
    {
        $subtitle = $this->makeSubtitle();
        $subtitle->getCues()[1]->setLines("Read by OCR");

        $this->assertStringContainsString("Read by OCR", $subtitle->format(SubRipFormatter::class));
    }


    public function testFormatAfterRecognizeTextWritesTheEngineText(): void
    {
        $output = $this->makeSubtitle()->recognizeText(new FakeOcrEngine(["Fixed text"]))->format(SubRipFormatter::class);

        $this->assertSame("\u{FEFF}1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n" .
                          "2\n00:00:03,000 --> 00:00:04,000\nFixed text\n\n" .
                          "3\n00:00:05,000 --> 00:00:06,000\nLast\n", $output);
    }


    public function testImageFormatterGetsImageCuesWithoutText(): void
    {
        $formatter = new class extends SubtitleFormatter implements ImageFormatter {
            public function format(Subtitle $subtitle, array $options = []): string
            {
                return implode(",", array_map(fn (SubtitleCue $cue): string =>
                    CueImage::isImageCue($cue) ? "image" : $cue->getText(), $subtitle->getCues()));
            }
        };

        $subtitle = $this->makeSubtitle();

        $this->assertSame("First,image,Last", $subtitle->format($formatter::class));
        $this->assertSame("First,image,Last",
                          $subtitle->format($formatter::class, [SubtitleFormatter::OPTION_SKIP_IMAGE_CUES => true]));
    }
}
