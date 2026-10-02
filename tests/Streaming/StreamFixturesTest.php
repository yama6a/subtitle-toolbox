<?php

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use Throwable;

class StreamFixturesTest extends TestCase
{
    private const OPTION_SETS = [
        "default"    => [],
        "CRLF"       => [SubtitleFormatter::OPTION_LINE_ENDING => "\r\n"],
        "no BOM"     => [SubtitleFormatter::OPTION_BOM => false],
        "strip tags" => [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS],
    ];


    public static function subRipFiles(): array
    {
        return self::fixtures("srt");
    }


    public static function webVttFiles(): array
    {
        return self::fixtures("vtt");
    }


    public static function validSubRipFiles(): array
    {
        return array_filter(self::fixtures("srt"), fn (array $file): bool => self::parses(new SubRipParser(), $file[0]));
    }


    public static function validWebVttFiles(): array
    {
        return array_filter(self::fixtures("vtt"), fn (array $file): bool => self::parses(new WebVttParser(), $file[0]));
    }


    #[DataProvider("subRipFiles")]
    public function testSubRipReaderReturnsTheCuesOfSubRipParser(string $path): void
    {
        $content = file_get_contents($path);

        $this->assertSameResult(
            fn (): array => [(new SubRipParser())->parse($content)->getCues()],
            fn (): array => [iterator_to_array((new SubRipStreamReader())->read($path), false)]
        );
    }


    #[DataProvider("webVttFiles")]
    public function testWebVttReaderReturnsTheCuesAndHeaderOfWebVttParser(string $path): void
    {
        $content = file_get_contents($path);
        $reader  = new WebVttStreamReader();

        $this->assertSameResult(
            function () use ($content): array {
                $subtitle = (new WebVttParser())->parse($content);

                return [$subtitle->getCues(), $subtitle->getFormatData(WebVttParser::FORMAT)];
            },
            fn (): array => [iterator_to_array($reader->read($path), false), $reader->getHeader()]
        );
    }


    #[DataProvider("validSubRipFiles")]
    public function testSubRipWriterWritesTheBytesOfSubRipFormatter(string $path): void
    {
        $subtitle = (new SubRipParser())->parse(file_get_contents($path));

        foreach (self::OPTION_SETS as $name => $options) {
            $stream = fopen("php://memory", "w+b");
            $writer = new SubRipStreamWriter($stream, $options);
            foreach ($subtitle->getCues() as $cue) {
                $writer->write($cue);
            }
            $writer->close();

            $this->assertSame((new SubRipFormatter())->format($subtitle, $options), $this->contents($stream), $name);
        }
    }


    #[DataProvider("validWebVttFiles")]
    public function testWebVttWriterWritesTheBytesOfWebVttFormatter(string $path): void
    {
        $parsed   = (new WebVttParser())->parse(file_get_contents($path));
        $header   = $parsed->getFormatData(WebVttParser::FORMAT);
        $subtitle = (new Subtitle())->setFormatData(WebVttParser::FORMAT, $header);
        foreach ($parsed->getCues() as $cue) {
            $subtitle->addCue($cue, false);
        }

        foreach (self::OPTION_SETS as $name => $options) {
            $stream = fopen("php://memory", "w+b");
            $writer = new WebVttStreamWriter($stream, $header, $options);
            foreach ($subtitle->getCues() as $cue) {
                $writer->write($cue);
            }
            $writer->close();

            $this->assertSame((new WebVttFormatter())->format($subtitle, $options), $this->contents($stream), $name);
        }
    }


    private static function fixtures(string $extension): array
    {
        $root  = dirname(__DIR__) . "/files";
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->getExtension() === $extension) {
                $files[substr($item->getPathname(), strlen($root) + 1)] = [$item->getPathname()];
            }
        }
        ksort($files);

        return $files;
    }


    /**
     * Compares the results, whose first item is the cue list, or the exceptions when the batch parser throws.
     */
    private function assertSameResult(callable $batch, callable $stream): void
    {
        try {
            $expected = $batch();
        } catch (Throwable $exception) {
            try {
                $stream();
            } catch (Throwable $streamException) {
                $this->assertSame($exception::class, $streamException::class);
                $this->assertSame($exception->getMessage(), $streamException->getMessage());

                return;
            }
            $this->fail("The stream reader does not throw: " . $exception->getMessage());
        }

        $actual = $stream();
        // Subtitle sorts its cues by start time, and the stream reader keeps the file order.
        usort($actual[0], fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());

        $this->assertEquals($expected, $actual);
    }


    private static function parses(SubRipParser|WebVttParser $parser, string $path): bool
    {
        try {
            $parser->parse(file_get_contents($path));
        } catch (Throwable) {
            return false;
        }

        return true;
    }


    private function contents($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }
}
