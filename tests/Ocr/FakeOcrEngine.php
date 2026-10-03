<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Image\CueImage;

final class FakeOcrEngine implements OcrEngine
{
    /** @var list<array{image: CueImage, language: ?string}> */
    public array $calls = [];


    /**
     * @param list<string> $lines
     */
    public function __construct(private readonly array $lines = ["Fixed text"], private readonly ?float $confidence = 0.9)
    {
    }


    public function recognize(CueImage $image, ?string $language): OcrResult
    {
        $this->calls[] = ["image" => $image, "language" => $language];

        return new OcrResult($this->lines, $this->confidence);
    }
}
