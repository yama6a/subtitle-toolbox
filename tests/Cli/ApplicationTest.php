<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Subtitle;

class ApplicationTest extends TestCase
{
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private static function runApplication(array $arguments, string $stdin = ""): array
    {
        $streams = [fopen("php://memory", "w+b"), fopen("php://memory", "w+b"), fopen("php://memory", "w+b")];
        fwrite($streams[0], $stdin);
        rewind($streams[0]);

        $code = (new Application(...$streams))->run(["subtitle-toolbox", ...$arguments]);

        rewind($streams[1]);
        rewind($streams[2]);

        return [$code, stream_get_contents($streams[1]), stream_get_contents($streams[2])];
    }


    public function testConvertsStandardInput(): void
    {
        $srt = file_get_contents(__DIR__ . "/../files/cli/trip.srt");

        $this->assertSame([0, Subtitle::fromStringAutoDetectFormat($srt)->toString(Format::WebVtt), ""], self::runApplication(["convert", "-", "--to", "vtt"], $srt));
    }


    public function testInfoOfStandardInput(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["info", "-", "--json"], file_get_contents(__DIR__ . "/../files/cli/shop.vtt"));

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertSame(["file" => "stdin", "format" => "vtt"], array_slice(json_decode($stdout, true), 0, 2));
    }


    public function testUsageErrorsExitWith2(): void
    {
        $this->assertSame(
            [2, "", "Error: Pass --shift SECONDS, --scale FACTOR, or --from-fps RATE and --to-fps RATE.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            self::runApplication(["retime", "-"])
        );
        $this->assertSame(
            [2, "", "Error: Pass --from-fps and --to-fps together.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            self::runApplication(["retime", "-", "--from-fps", "25"])
        );
        $this->assertSame(
            [2, "", "Error: --shift-after needs --shift.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            self::runApplication(["retime", "-", "--scale", "2", "--shift-after", "1"])
        );
        $this->assertSame(
            [2, "", "shift is deprecated. Use: subtitle-toolbox retime -\n" .
                    "Error: Pass --by SECONDS.\nRun \"subtitle-toolbox help shift\" for the usage.\n"],
            self::runApplication(["shift", "-"])
        );
        $this->assertSame(
            [2, "", "fps was removed. Use: subtitle-toolbox retime - --to-fps 25\n"],
            self::runApplication(["sync-fps", "-", "--to", "25"])
        );
    }


    public function testRetimeHelpMatchesItsOptions(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["retime", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith(
            "Usage: subtitle-toolbox retime <input>... [--shift SECONDS] [--scale FACTOR] [--from-fps RATE --to-fps RATE] [options]\n\n" .
            "Shifts and scales all cue times, or fits them to a video with another frame rate.\n\n" .
            "Pass one or more edits. retime applies them in this order: --shift, --scale, --from-fps and --to-fps.\n",
            $stdout
        );
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame([
            "shift", "shift-after", "scale", "from-fps", "to-fps", "to", "output", "output-dir", "in-place", "force", "output-fps",
            "line-ending", "bom", "no-bom", "skip-image-cues", "from", "encoding", "lenient", "input-fps", "fps", "word-timestamps",
            "track", "keep-going", "help",
        ], $matches[1]);
        $this->assertMatchesRegularExpression('/^  --shift-after SECONDS +Shift only the cues that start at this time or later\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --from-fps RATE +Frame rate of the video that the subtitle fits now\. Needs --to-fps\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps and --output-fps\. Each of them overrides it\.$/m', $stdout);
        $this->assertSame(array_map(fn (Option $option): string => $option->name, (new RetimeCommand())->options()), array_slice($matches[1], 0, -1));
    }


    public function testFileErrorsExitWith1(): void
    {
        $this->assertSame([1, "", "stdin: UnknownFormatException (Error #106): Format detection found no subtitle format. Call fromString() " .
                                  "with a format. Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram.\n"],
                          self::runApplication(["info", "-"], "hello"));
        $this->assertSame([1, "", "stdin: VobSub needs the path of the .idx file. Standard input does not work.\n"],
                          self::runApplication(["info", "-", "--from", "vobsub"], "hello"));
    }


    public function testChaptersAndCloudSpeechJsonNeedFrom(): void
    {
        $chapters = __DIR__ . "/../files/chapters/ffmetadata/real/m4b_audiobook.ffmeta";
        $deepgram = file_get_contents(__DIR__ . "/../files/deepgram/real/pool_utterances_diarize.json");

        $unknown = "UnknownFormatException (Error #106): Format detection found no subtitle format. Call %s with a format. " .
                   "Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram.\n";

        $this->assertSame([1, "", "$chapters: " . sprintf($unknown, "load()")], self::runApplication(["info", $chapters]));
        $this->assertSame([1, "", "stdin: " . sprintf($unknown, "fromString()")], self::runApplication(["info", "-"], $deepgram));

        [$code, $stdout] = self::runApplication(["info", $chapters, "--from", "ffmeta"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("ffmeta", $stdout);

        [$code, $stdout] = self::runApplication(["info", "-", "--from", "deepgram"], $deepgram);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("deepgram", $stdout);
    }


    #[RunInSeparateProcess]
    public function testOcrWithoutEitherEngineFailsWithBothInstallHints(): void
    {
        putenv("PATH=" . __DIR__);
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->unregister();
            spl_autoload_register(function (string $class) use ($loader): void {
                if (!str_starts_with($class, "GlyphOcr\\")) {
                    $loader->loadClass($class);
                }
            });
        }

        $this->assertSame(
            [2, "", "Error: Cannot run OCR - neither Tesseract nor the package yama6a/php-glyph-ocr is installed! " .
                    TesseractOcrEngine::INSTALL_HINT . " Or install php-glyph-ocr with: composer require yama6a/php-glyph-ocr\n" .
                    "Run \"subtitle-toolbox help convert\" for the usage.\n"],
            self::runApplication(["convert", __DIR__ . "/../files/pgs/text_1080p.sup", "--to", "srt", "--output", "-", "--ocr"])
        );
    }
}
