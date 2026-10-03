<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;
use Throwable;

class StreamFixturesTest extends TestCase
{
    /**
     * @return array<string, WriteOptions>
     */
    private static function optionSets(): array
    {
        return [
            "default"    => new WriteOptions(),
            "CRLF"       => new WriteOptions(lineEnding: LineEnding::Crlf),
            "no BOM"     => new WriteOptions(bom: false),
            "strip tags" => new WriteOptions(stripTags: true),
        ];
    }


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
            fn (): array => [(new SubRipParser())->parse($content, new ReadOptions())->getCues()],
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
                $subtitle = (new WebVttParser())->parse($content, new ReadOptions());

                return [$subtitle->getCues(), $subtitle->getFormatData(WebVttParser::FORMAT)];
            },
            fn (): array => [iterator_to_array($reader->read($path), false), $reader->getHeader()]
        );
    }


    #[DataProvider("validSubRipFiles")]
    public function testSubRipWriterWritesTheBytesOfSubRipFormatter(string $path): void
    {
        $subtitle = (new SubRipParser())->parse(file_get_contents($path), new ReadOptions());

        foreach (self::optionSets() as $name => $options) {
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
        $parsed   = (new WebVttParser())->parse(file_get_contents($path), new ReadOptions());
        $header   = $parsed->getFormatData(WebVttParser::FORMAT);
        $subtitle = (new Subtitle())->setFormatData(WebVttParser::FORMAT, $header);
        foreach ($parsed->getCues() as $cue) {
            $subtitle->addCue($cue, false);
        }

        foreach (self::optionSets() as $name => $options) {
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
            $parser->parse(file_get_contents($path), new ReadOptions());
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
