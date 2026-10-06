<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Parsers\YouTubeTimedTextParser;

class XmlLoaderTest extends TestCase
{
    private bool $previous;


    protected function setUp(): void
    {
        $this->previous = libxml_use_internal_errors();
    }


    protected function tearDown(): void
    {
        libxml_use_internal_errors($this->previous);
    }


    public static function failedLoads(): array
    {
        return [
            "TTML"             => [fn () => (new TtmlParser())->parse("<tt><body>", new ReadOptions())],
            "YouTube XML"      => [fn () => (new YouTubeTimedTextParser())->parse("<transcript><text>", new ReadOptions())],
            "OCR replace list" => [fn () => OcrReplaceList::fromSubtitleEditXml("<OCRFixReplaceList><WholeWords>")],
        ];
    }


    #[DataProvider("failedLoads")]
    public function testAFailedLoadKeepsTheLibxmlErrorSetting(Closure $load): void
    {
        foreach ([false, true] as $setting) {
            libxml_use_internal_errors($setting);
            try {
                $load();
                $this->fail("The load did not throw.");
            } catch (ParsingException) {
            }

            $this->assertSame($setting, libxml_use_internal_errors());
            $this->assertFalse(libxml_get_last_error());
        }
    }


    public function testReturnsNullAndTheErrorForXmlThatIsNotWellFormed(): void
    {
        $this->assertNull(XmlLoader::xml("<tt><p>", $error));
        $this->assertSame(1, $error->line);
        $this->assertNull(XmlLoader::xml("", $error));
        $this->assertNull($error);
    }
}
