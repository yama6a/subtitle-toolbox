<?php

namespace SubtitleToolbox\Ocr;

use Closure;
use Composer\Autoload\ClassLoader;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\RecognitionResult;
use GlyphOcr\RecognizedChar;
use GlyphOcr\RecognizedLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Image\PngEncoder;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PgsFixtures;
use SubtitleToolbox\Parsers\PgsParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\Subtitle;

require_once __DIR__ . "/../files/pgs/generator/PgsFixtures.php";
require_once __DIR__ . "/../files/vobsub/generate.php";

class GlyphOcrEngineTest extends TestCase
{
    private const PGS    = __DIR__ . "/../files/pgs/";
    private const VOBSUB = __DIR__ . "/../files/vobsub/";


    /**
     * Each set: the parser call, the golden OCR output, the drawn text of each cue and the minimum character accuracy.
     *
     * @return array<string, array{Closure, string, list<list<string>>, float}>
     */
    public static function fixtureSets(): array
    {
        return [
            "PGS 1080p, 44 to 60 px" => [
                fn (): Subtitle => (new PgsParser())->parse(file_get_contents(self::PGS . "text_1080p.sup")),
                self::PGS . "text_1080p.ocr.srt",
                array_column(PgsFixtures::TEXT_CUES, 2),
                0.97,
            ],
            "VobSub 576p, 24 to 30 px" => [
                fn (): Subtitle => (new VobSubParser(file_get_contents(self::VOBSUB . "text-pal.idx")))
                    ->parse(file_get_contents(self::VOBSUB . "text-pal.sub")),
                self::VOBSUB . "text-pal.ocr.srt",
                array_column(TEXT_CUES, 2),
                0.69,
            ],
        ];
    }


    #[DataProvider("fixtureSets")]
    public function testReadsTheFixtureSetAsTheGoldenFileWithTheMinimumAccuracy(Closure $parse, string $golden,
                                                                                  array $drawnLines, float $minimum): void
    {
        $subtitle = $parse()->recognizeText(new GlyphOcrEngine());

        $this->assertStringEqualsFile($golden, $subtitle->format(SubRipFormatter::class));

        $errors = 0;
        $length = 0;
        foreach ($subtitle->getCues() as $index => $cue) {
            $expected = implode("\n", Markup::plainLines($drawnLines[$index]));
            $errors  += levenshtein($expected, implode("\n", Markup::plainLines($cue->getLines())));
            $length  += strlen($expected);
            $this->assertTrue(CueImage::isImageCue($cue));
        }
        $this->assertCount(count($drawnLines), $subtitle->getCues());
        $this->assertGreaterThanOrEqual($minimum, round(1 - $errors / $length, 4));
    }


    public function testTextFixturesMatchTheGenerators(): void
    {
        $this->assertSame(file_get_contents(self::PGS . "text_1080p.sup"), PgsFixtures::text1080p());
        $this->assertSame([file_get_contents(self::VOBSUB . "text-pal.idx"), file_get_contents(self::VOBSUB . "text-pal.sub")],
                          textFixture());
    }


    private static function char(string $text, bool $italic = false, float $confidence = 1.0): RecognizedChar
    {
        return new RecognizedChar($text, $confidence, $italic, $text === " ", null, null);
    }


    /**
     * @param string $pattern one character per entry, where an upper case "I" marks an italic character
     */
    private static function line(string $text, string $pattern): RecognizedLine
    {
        return new RecognizedLine(array_map(fn (string $char, string $flag): RecognizedChar => self::char($char, $flag === "I"),
                                            str_split($text), str_split($pattern)));
    }


    public function testItalicWordsBecomeOneItalicRun(): void
    {
        $result = GlyphOcrEngine::toOcrResult(new RecognitionResult([
            self::line("The wind turns", "II.IIIII.IIIII"),
            self::line("Snow is here.", "IIII.I...I..."),
            self::line("one more word", "...I.IIII.III"),
        ]));

        $this->assertSame(["<i>The wind turns</i>", "<i>Snow</i> is here.", "one <i>more word</i>"], $result->lines);
    }


    public function testEscapesMarkupCharactersAndUsesTheMeanConfidence(): void
    {
        $result = GlyphOcrEngine::toOcrResult(new RecognitionResult([
            new RecognizedLine([self::char("<", false, 0.5), self::char("&", false, 1.0), self::char(" "),
                                self::char("b", false, 0.6)]),
        ]));

        $this->assertSame(["&lt;&amp; b"], $result->lines);
        $this->assertEqualsWithDelta(0.7, $result->confidence, 1e-9);
    }


    public function testAnImageWithoutInkGivesNoLines(): void
    {
        $image = new CueImage(PngEncoder::encode(4, 4, array_fill(0, 16, 0)), 0, 0, 4, 4, 720, 576);

        $result = (new GlyphOcrEngine())->recognize($image, null);

        $this->assertSame([], $result->lines);
        $this->assertSame(0.0, $result->confidence);
    }


    public function testPassesTheDatabaseAndTheOptionsToTheRecognizer(): void
    {
        $subtitle = (new PgsParser())->parse(file_get_contents(self::PGS . "text_1080p.sup"));
        $image    = CueImage::fromCue($subtitle->getCues()[0]);

        $result = (new GlyphOcrEngine(new GlyphDatabase(), ["unknownText" => "#"]))->recognize($image, "eng");

        $this->assertCount(1, $result->lines);
        $this->assertMatchesRegularExpression("/^#+( #+)+$/", $result->lines[0]);
        $this->assertSame(0.0, $result->confidence);
    }


    public function testUnknownOptionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot create a GlyphOcrEngine with the option \"database\"");

        new GlyphOcrEngine(null, ["database" => GlyphDatabase::latin()]);
    }


    public function testInvalidOptionValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot create a GlyphOcrEngine - the recognizer says: Cannot create a recognizer " .
                                      "with ink threshold 0");

        new GlyphOcrEngine(null, ["inkThreshold" => 0]);
    }


    public function testImageThatIsNoPngThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot read the cue image at 3, 4 - the recognizer says: Cannot decode the PNG");

        (new GlyphOcrEngine())->recognize(new CueImage("no png", 3, 4, 1, 1, 720, 576), null);
    }


    #[RunInSeparateProcess]
    public function testThrowsWithAnInstallHintWhenThePackageIsMissing(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (!str_starts_with($class, "GlyphOcr\\")) {
                    $loader->loadClass($class);
                }
            });
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("the package yama6a/php-glyph-ocr is missing! " .
                                      "Install it with: composer require yama6a/php-glyph-ocr");

        new GlyphOcrEngine();
    }
}
