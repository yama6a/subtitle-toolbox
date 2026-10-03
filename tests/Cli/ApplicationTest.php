<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Http\LocalServer;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\TranslationEngine;
use SubtitleToolbox\Translation\TranslationRunner;

require_once __DIR__ . "/../Http/FakeHttpClient.php";
require_once __DIR__ . "/../Http/LocalServer.php";

class ApplicationTest extends TestCase
{
    private const TRANSLATION = __DIR__ . "/../files/translation/";

    private const KEY_VARIABLES = ["DEEPL_API_KEY", "GOOGLE_TRANSLATE_API_KEY", "SUBTITLE_TOOLBOX_TRANSLATE_URL"];

    private ?LocalServer $server = null;

    private string $serverLog = "";


    protected function tearDown(): void
    {
        $this->server?->stop();
        if ($this->serverLog !== "") {
            @unlink($this->serverLog);
        }
        foreach (self::KEY_VARIABLES as $variable) {
            putenv($variable);
        }
    }


    /**
     * Starts the fake DeepL and Google server, points translate at it and sets the environment variables of $keys.
     *
     * @param array<string, string> $keys
     */
    private function startTranslateServer(array $keys = []): void
    {
        $this->serverLog = tempnam(sys_get_temp_dir(), "translate-log");
        $this->server    = LocalServer::start(self::TRANSLATION . "fake-server.php", ["FAKE_SERVER_LOG" => $this->serverLog]);
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=" . $this->server->url);
        foreach ($keys as $variable => $key) {
            putenv("$variable=$key");
        }
    }


    /**
     * @return list<array{path: string, key: string, body: array}>
     */
    private function serverRequests(): array
    {
        return array_map(fn (string $line): array => json_decode($line, true), file($this->serverLog, FILE_IGNORE_NEW_LINES));
    }


    private static function recordedTranslation(TranslationEngine $engine, string $source, string $target, Format $format): string
    {
        $subtitle = Subtitle::load(self::TRANSLATION . "own_station.srt", Format::SubRip);

        return (new TranslationRunner($engine))->translate($subtitle, $source, $target)->toString($format);
    }

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


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithDeepLReadsTheKeyOfTheEnvironment(): void
    {
        $this->startTranslateServer(["DEEPL_API_KEY" => "env-key", "GOOGLE_TRANSLATE_API_KEY" => "google-key"]);

        [$code, $stdout, $stderr] = self::runApplication(["translate", self::TRANSLATION . "own_station.srt", "--engine", "deepl",
                                                          "--source-language", "en", "--target-language", "de", "--to", "vtt"]);

        $expected = self::recordedTranslation(new DeepLEngine("key", null, new FakeHttpClient([[200, file_get_contents(self::TRANSLATION . "deepl_de.json")]])),
                                              "en", "de", Format::WebVtt);
        $this->assertSame([0, $expected, ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("\num <b>10:15</b> von Gleis 4 ab.\n", $stdout);
        $requests = $this->serverRequests();
        $this->assertSame([["/v2/translate", "env-key", "DE", "EN"]],
                          array_map(fn (array $request): array => [$request["path"], $request["key"], $request["body"]["target_lang"],
                                                                   $request["body"]["source_lang"]], $requests));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithGoogleAndAnApiKeyThatWinsOverTheEnvironment(): void
    {
        $this->startTranslateServer(["GOOGLE_TRANSLATE_API_KEY" => "env-key"]);
        $input  = self::TRANSLATION . "own_station.srt";
        $output = sys_get_temp_dir() . "/translate-" . bin2hex(random_bytes(6)) . ".srt";

        try {
            [$code, $stdout, $stderr] = self::runApplication(["translate", $input, "--engine", "google", "--api-key", "cli-key",
                                                              "--target-language", "fr", "-o", $output]);

            $this->assertSame([0, "$input -> $output\n", ""], [$code, $stdout, $stderr]);
            $expected = self::recordedTranslation(new GoogleTranslateEngine("key", null, new FakeHttpClient([[200, file_get_contents(self::TRANSLATION . "google_fr.json")]])),
                                                  "", "fr", Format::SubRip);
            $this->assertSame($expected, file_get_contents($output));
        } finally {
            @unlink($output);
        }
        $request = $this->serverRequests()[0];
        $this->assertSame(["/language/translate/v2", "cli-key"], [$request["path"], $request["key"]]);
        $this->assertSame(["target" => "fr", "format" => "html"], array_slice($request["body"], 1));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateFailsTheFileOnAnHttpErrorWithoutPrintingTheKey(): void
    {
        $this->startTranslateServer();

        $this->assertSame(
            [1, "", "stdin: DeepL rejected the API key (HTTP 403). Check the key and its plan.\n"],
            self::runApplication(["translate", "-", "--engine", "deepl", "--api-key", "forbidden", "--target-language", "de"], "1\n00:00:01,000 --> 00:00:02,000\nHello\n")
        );
    }


    public function testTranslateUsageErrorsExitWith2(): void
    {
        putenv("GOOGLE_TRANSLATE_API_KEY=google-key");
        $usage = "\nRun \"subtitle-toolbox help translate\" for the usage.\n";

        $this->assertSame([2, "", "Error: Pass --engine deepl or --engine google.$usage"],
                          self::runApplication(["translate", "-", "--target-language", "de"]));
        $this->assertSame([2, "", "Error: The option --engine must be deepl or google, got \"bing\".$usage"],
                          self::runApplication(["translate", "-", "--engine", "bing", "--target-language", "de"]));
        $this->assertSame([2, "", "Error: Pass --api-key or set the environment variable DEEPL_API_KEY.$usage"],
                          self::runApplication(["translate", "-", "--engine", "deepl", "--target-language", "de"]));
        $this->assertSame([2, "", "Error: Pass --target-language, for example --target-language fr.$usage"],
                          self::runApplication(["translate", "-", "--engine", "google"]));
    }


    public function testTranslateHelpListsTheOptionsButNoKeyAndNoTestUrl(): void
    {
        putenv("DEEPL_API_KEY=secret-env-key");

        [$code, $stdout, $stderr] = self::runApplication(["translate", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: subtitle-toolbox translate <input>... --engine deepl|google --target-language CODE " .
                                      "[--source-language CODE] [--api-key KEY] [options]\n", $stdout);
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame(["engine", "api-key", "source-language", "target-language", "to", "output"], array_slice($matches[1], 0, 6));
        $this->assertStringNotContainsString("secret-env-key", $stdout);
        $this->assertStringNotContainsString("SUBTITLE_TOOLBOX_TRANSLATE_URL", $stdout);
        $this->assertStringContainsString("  translate  ", self::runApplication(["--help"])[1]);
    }


    public function testWithoutTheCurlExtensionTranslateExitsWith1AndOtherCommandsRun(): void
    {
        $run = function (array $arguments): array {
            $process = proc_open(
                [PHP_BINARY, "-d", "disable_functions=curl_init", __DIR__ . "/../../bin/subtitle-toolbox", ...$arguments],
                [0 => ["file", PHP_OS_FAMILY === "Windows" ? "NUL" : "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
                $pipes
            );
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            return [proc_close($process), $stdout, $stderr];
        };
        $input = self::TRANSLATION . "own_station.srt";

        $this->assertSame(
            [1, "", "Error: The translate engines need the PHP extension curl. Install ext-curl, for example php8.2-curl.\n"],
            $run(["translate", $input, "--engine", "deepl", "--api-key", "key", "--target-language", "de"])
        );
        [$code, $stdout, $stderr] = $run(["convert", $input, "--to", "vtt", "--output", "-"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("WEBVTT", ltrim($stdout, "\u{FEFF}"));
    }
}
