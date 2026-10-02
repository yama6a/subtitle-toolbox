<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;

class JsonRealFilesTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/json/real/";


    public static function realFiles(): array
    {
        return [
            "Aegisub ASS" => [
                "own_aegisub.json",
                7,
                [1.0, 3.5, "<v Conductor>Good morning, everyone."],
                [16.1, 18.0, "<v Passenger><u>Thank you</u>, and <s>good night</s> good day."],
            ],
            "SubRip with alignment" => [
                "own_alignment_and_coordinates.json",
                10,
                [1.0, 3.0, "Plain first cue"],
                [19.5, 21.0, "Keep this: {\\some_unknown_tag} and {normal text}"],
            ],
            "image cues" => [
                "own_image_cues.json",
                2,
                [1.0, 2.5, ""],
                [3.0, 4.25, "Platform four"],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = Subtitle::parse(file_get_contents(self::DIR . $fileName), JsonParser::class)->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesAByteForByteRoundTrip(string $fileName): void
    {
        $json = file_get_contents(self::DIR . $fileName);

        $this->assertSame($json, Subtitle::parse($json)->format(JsonFormatter::class, [JsonFormatter::OPTION_PRETTY_PRINT => true]));
    }


    public function testAegisubFileKeepsMetadataCommentsAndFormatData(): void
    {
        $json     = Subtitle::parse(file_get_contents(self::DIR . "own_aegisub.json"));
        $original = Subtitle::parse(file_get_contents(__DIR__ . "/../files/ass/real/own_aegisub.ass"));

        $this->assertEquals($original, $json);
        $this->assertSame("Morning train to the coast", $json->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertCount(3, $json->getComments());
        $this->assertSame("V4+ Styles", $json->getFormatData("ass")["stylesSection"]);
    }


    public function testImageCueFileHoldsThePngBytes(): void
    {
        $image = CueImage::fromCue(Subtitle::parse(file_get_contents(self::DIR . "own_image_cues.json"))->getCues()[0]);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image->png);
        $this->assertSame([640, 940, 2, 1, 1920, 1080, true],
                          [$image->x, $image->y, $image->width, $image->height, $image->screenWidth, $image->screenHeight, $image->forced]);
    }
}
