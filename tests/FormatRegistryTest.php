<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\MicroDvdFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\IttParser;
use SubtitleToolbox\Parsers\JsonParser;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\Mpl2Parser;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\TmPlayerParser;
use SubtitleToolbox\Parsers\TtmlParser;

class FormatRegistryTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string, list<string>}>
     */
    public static function classDirectories(): array
    {
        return [
            "parsers"    => ["Parsers", Parsers\SubtitleParser::class, FormatRegistry::parserClasses()],
            "formatters" => ["Formatters", Formatters\SubtitleFormatter::class, FormatRegistry::formatterClasses()],
        ];
    }


    #[DataProvider("classDirectories")]
    public function testEveryParserAndFormatterClassHasAFormat(string $directory, string $baseClass, array $registered): void
    {
        $classes = [];
        foreach (glob(__DIR__ . "/../src/$directory/*.php") as $path) {
            $class = "SubtitleToolbox\\$directory\\" . basename($path, ".php");
            if ((new \ReflectionClass($class))->isInstantiable() && is_subclass_of($class, $baseClass)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        $registered = array_unique($registered);
        sort($registered);

        $this->assertNotEmpty($classes);
        $this->assertSame($classes, $registered);
    }


    public function testEveryDetectedParserHasAFormat(): void
    {
        $signatures = (new \ReflectionClassConstant(FormatDetector::class, "SIGNATURES"))->getValue();

        foreach (array_keys($signatures) as $parser) {
            $this->assertNotNull(FormatRegistry::forParser($parser), $parser);
        }
    }


    public function testEveryWritableFormatHasAFileExtension(): void
    {
        foreach (FormatRegistry::names() as $name) {
            if (FormatRegistry::formatterClass($name) !== null) {
                $this->assertNotEmpty(FormatRegistry::extensions($name), $name);
            }
        }
    }


    public function testLookups(): void
    {
        $this->assertSame(SubRipParser::class, FormatRegistry::parserClass("srt"));
        $this->assertSame(SubRipFormatter::class, FormatRegistry::formatterClass("srt"));
        $this->assertSame("microdvd", FormatRegistry::forExtension("sub"));
        $this->assertSame("microdvd", FormatRegistry::forPath("season1/Movie.SUB"));
        $this->assertSame(MicroDvdFormatter::class, FormatRegistry::formatterClass(FormatRegistry::forPath("movie.sub")));
        $this->assertSame("json", FormatRegistry::forExtension(".json"));
        $this->assertSame("txt", FormatRegistry::forExtension("txt"));
        $this->assertSame(["txt"], FormatRegistry::extensions("mpl2"));
        $this->assertSame(["txt"], FormatRegistry::extensions("tmplayer"));
        $this->assertSame("ass", FormatRegistry::find("ssa"));
        $this->assertSame("srt", FormatRegistry::find("SRT"));
        $this->assertSame("ttml", FormatRegistry::find(".dfxp"));
        $this->assertSame("tsv", FormatRegistry::forPath("script.TSV"));
        $this->assertSame(Parsers\CsvParser::class, FormatRegistry::parserClass("tsv"));
        $this->assertSame("csv", FormatRegistry::forParser(Parsers\CsvParser::class));
        $this->assertSame("ffmeta", FormatRegistry::forPath("chapters.ffmeta"));
        $this->assertSame("txt", FormatRegistry::forExtension("txt"));
        $this->assertSame("json", FormatRegistry::forPath("chapters.json"));
        $this->assertSame(Parsers\PodcastChaptersParser::class, FormatRegistry::parserClass("podcast"));
        $this->assertSame(Formatters\YouTubeChaptersFormatter::class, FormatRegistry::formatterClass(FormatRegistry::find("ytchapter")));
        $this->assertSame(["txt"], FormatRegistry::extensions("ogm"));
        $this->assertNull(FormatRegistry::find("doc"));
        $this->assertNull(FormatRegistry::forPath("README"));
        $this->assertNull(FormatRegistry::parserClass("txt"));
        $this->assertNull(FormatRegistry::formatterClass("vobsub"));
        $this->assertNull(FormatRegistry::parserClass("unknown"));
        $this->assertSame([], FormatRegistry::extensions("unknown"));
    }


    public function testForParserNamesTheExactParser(): void
    {
        $this->assertSame("ttml", FormatRegistry::forParser(TtmlParser::class));
        $this->assertSame("itt", FormatRegistry::forParser(IttParser::class));
        $this->assertSame("ass", FormatRegistry::forParser(AssParser::class));
        $this->assertSame("json", FormatRegistry::forParser(JsonParser::class));
        $this->assertSame("microdvd", FormatRegistry::forParser(MicroDvdParser::class));
        $this->assertSame("mpl2", FormatRegistry::forParser(Mpl2Parser::class));
        $this->assertSame("tmplayer", FormatRegistry::forParser(TmPlayerParser::class));
        $this->assertNull(FormatRegistry::forParser(\stdClass::class));
    }
}
