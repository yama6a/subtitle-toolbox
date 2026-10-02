<?php

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Ocr\FakeOcrEngine;

require_once __DIR__ . "/../Ocr/FakeOcrEngine.php";

class OcrProgressTest extends TestCase
{
    public function testPrintsTheCountEveryHundredImagesAndAfterTheLast(): void
    {
        $stderr   = fopen("php://memory", "w+b");
        $engine   = new FakeOcrEngine(["A line"], 0.5);
        $progress = new OcrProgress($engine, new Console(fopen("php://memory", "rb"), fopen("php://memory", "wb"), $stderr),
                                    "movie.sup", 250);
        $image    = new CueImage(PngEncoder::encode(1, 1, [0xFFFFFFFF]), 0, 0, 1, 1, 720, 576);

        for ($index = 0; $index < 250; $index++) {
            $result = $progress->recognize($image, "eng");
        }

        rewind($stderr);
        $this->assertSame("movie.sup: OCR 100/250\nmovie.sup: OCR 200/250\nmovie.sup: OCR 250/250\n", stream_get_contents($stderr));
        $this->assertSame(["A line"], $result->lines);
        $this->assertSame(0.5, $result->confidence);
        $this->assertCount(250, $engine->calls);
        $this->assertSame("eng", $engine->calls[0]["language"]);
    }
}
