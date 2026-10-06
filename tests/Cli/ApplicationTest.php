<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Http\FakeHttpClient;
use SubtitleToolbox\Http\LocalServer;
use SubtitleToolbox\Http\WithoutCurl;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\Speakers\SpeakerLabelOptions;
use SubtitleToolbox\Speakers\SpeakerLabels;
use SubtitleToolbox\Speakers\SpeakerStyle;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Tests\Support\BinaryTestCase;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChangeTiming;
use SubtitleToolbox\Translation\DeepLEngine;
use SubtitleToolbox\Translation\DeepLOptions;
use SubtitleToolbox\Translation\GoogleTranslateEngine;
use SubtitleToolbox\Translation\GoogleTranslateOptions;
use SubtitleToolbox\Translation\TranslationEngine;
use SubtitleToolbox\Translation\TranslationRunner;

require_once __DIR__ . "/../Http/FakeHttpClient.php";
require_once __DIR__ . "/../Http/LocalServer.php";
require_once __DIR__ . "/../Http/WithoutCurl.php";

class ApplicationTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";

    private const TRANSLATION = __DIR__ . "/../files/translation/";

    private const TRANSLATE_VARIABLES = ["DEEPL_API_KEY", "GOOGLE_TRANSLATE_API_KEY", "SUBTITLE_TOOLBOX_TRANSLATE_URL"];

    private ?LocalServer $server = null;

    private string $serverLog = "";


    /** @var array<string, string|false> the values of TRANSLATE_VARIABLES before the test */
    private array $savedVariables = [];


    protected function setUp(): void
    {
        foreach (self::TRANSLATE_VARIABLES as $variable) {
            $this->savedVariables[$variable] = getenv($variable);
            putenv($variable);
        }
        // A translate test without the fake server must not reach a real service.
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=" . self::closedPortUrl());
    }


    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if ($this->serverLog !== "") {
            @unlink($this->serverLog);
        }
        foreach ($this->savedVariables as $variable => $value) {
            putenv($value === false ? $variable : "$variable=$value");
        }
    }


    private static function closedPortUrl(): string
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        return "http://$name";
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
        (new TranslationRunner($engine))->translate($subtitle, $source, $target);

        return $subtitle->toString($format);
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


    /**
     * Each option whose name holds a no- word, with what it turns off.
     */
    private const OFF_SWITCHES = [
        "no-bom"        => "the UTF-8 BOM of the output format",
        "no-scale"      => "the scale search of sync",
        "no-snap-chain" => "the closing of small gaps between cues",
    ];


    public function testOptionNamesFollowTheNamingRules(): void
    {
        $commands = (new \ReflectionProperty(Application::class, "commands"))->getValue(new Application());
        $names    = [];
        foreach ($commands as $command) {
            foreach ($command->options() as $option) {
                $where   = $command->name() . " --$option->name";
                $names[] = $option->name;
                if (preg_match('/(^|-)no-/', $option->name) === 1) {
                    $this->assertArrayHasKey($option->name, self::OFF_SWITCHES, "$where does not turn something off.");
                    $this->assertFalse($option->takesValue(), "$where takes a value.");
                    $this->assertStringStartsNotWith("Report", $option->description, "$where reports something.");
                }
                if (str_ends_with($option->name, "-frames") || $option->valueName === "FRAMES") {
                    $this->assertSame("FRAMES", $option->valueName, "$where must count frames.");
                    $this->assertStringEndsWith("-frames", $option->name, "$where counts frames.");
                }
                if ($option->valueName === "SECONDS") {
                    $this->assertStringEndsNotWith("-frames", $option->name, "$where is in seconds.");
                }
            }
        }
        $this->assertContains("check-overlaps", $names);
        $this->assertContains("snap-window-frames", $names);
        $this->assertEqualsCanonicalizing(array_keys(self::OFF_SWITCHES), array_values(array_unique(preg_grep('/(^|-)no-/', $names))));
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
        $this->assertSame(["file" => "-", "format" => "vtt"], array_slice(json_decode($stdout, true)[0], 0, 2));
    }


    public function testInfoPrintsADashForNumbersWithoutData(): void
    {
        [$code, $stdout] = self::runApplication(["info", "-"], "1\n00:00:01,000 --> 00:00:03,000\n<i> </i>\n");

        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Span:                  2 s\n", $stdout);
        $this->assertStringContainsString("  Characters per second: -\n", $stdout);
        $this->assertStringContainsString("  Gaps:                  -\n", $stdout);
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
            [2, "", "Error: Pass --shift with --shift-after.\nRun \"subtitle-toolbox help retime\" for the usage.\n"],
            self::runApplication(["retime", "-", "--scale", "2", "--shift-after", "1"])
        );
    }


    public function testCommandsThatTwoPointZeroRemovedAreUnknown(): void
    {
        foreach (["shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "snap"] as $command) {
            $this->assertSame(
                [2, "", "Error: Unknown command \"$command\".\nRun \"subtitle-toolbox help\" for the usage.\n"],
                self::runApplication([$command, "-", "--by", "1"])
            );
            $this->assertDoesNotMatchRegularExpression("/^  $command +\\S/m", self::runApplication(["--help"])[1]);
        }
    }


    public function testConvertHelpListsTheOptionGroupsInTheRunOrder(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["convert", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: subtitle-toolbox convert <input> --to FORMAT [-o FILE] [options]\n" .
                                      "       subtitle-toolbox convert <input>... --to FORMAT --output-dir DIR\n" .
                                      "                                [options]\n", $stdout);
        $this->assertMatchesRegularExpression('/^  --encoding NAME +/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --language CODE +/m', $stdout);
        $this->assertDoesNotMatchRegularExpression('/^  --(ocr|sdh|structure-wrap|shift|karaoke)\b/m', $stdout);
        preg_match_all('/^  ([a-z]+) {2,}[A-Z]/m', $stdout, $groups);
        $this->assertSame(["forced", "ocr", "errors", "sdh", "replace", "text", "structure", "retime", "snap", "timing", "masking", "karaoke", "ass"],
                          $groups[1]);
        $this->assertSame([0, $stdout, ""], self::runApplication(["help", "convert"]));
    }


    public function testConvertHelpOfOneGroupListsItsOptions(): void
    {
        $sdh = "sdh: Remove hearing-impaired annotations.\n" .
               "  --sdh                       Remove hearing-impaired annotations such as [DOOR\n" .
               "                              SLAMS], (laughs) and JOHN:. A cue with no text\n" .
               "                              left goes.\n";

        $this->assertStringStartsWith($sdh, self::runApplication(["convert", "--help", "sdh"])[1]);
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["convert", "-h", "sdh"]));
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["help", "convert", "sdh"]));
        $this->assertSame(9, substr_count(BinaryTestCase::unwrapHelp(self::runApplication(["convert", "--help", "sdh"])[1]), "\n"));
    }


    public function testConvertHelpAllListsEachOptionOnceUnderItsGroup(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["convert", "--help", "all"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith(strstr(self::runApplication(["convert", "--help"])[1], "\nOption groups", true), $stdout);
        $this->assertStringContainsString("\nretime: Shift and scale the times, or change the frame rate.\n  --shift SECONDS ", $stdout);
        foreach ((new ConvertCommand())->options() as $option) {
            $this->assertSame(1, preg_match_all('/^  ' . preg_quote($option->synopsis(), "/") . ' /m', $stdout), "--$option->name");
        }
    }


    public function testConvertHelpBeforeAFileArgumentPrintsTheConvertHelp(): void
    {
        $help = self::runApplication(["convert", "--help"]);
        $cwd  = getcwd();
        chdir(__DIR__ . "/../..");
        try {
            $this->assertSame([0, ""], [$help[0], $help[2]]);
            $this->assertSame($help, self::runApplication(["convert", "in.srt", "-h", "out.srt"]));
            $this->assertSame($help, self::runApplication(["convert", "-h", "season1/movie"]));
            $this->assertSame($help, self::runApplication(["convert", "-h", "LICENSE"]));
            $this->assertSame($help, self::runApplication(["help", "convert", "out.srt"]));
            $this->assertSame(self::runApplication(["convert", "--help", "text"]), self::runApplication(["convert", "in.srt", "-h", "text"]));
            $this->assertSame(2, self::runApplication(["convert", "in.srt", "-h", "out"])[0]);
        } finally {
            chdir($cwd);
        }
    }


    public function testConvertHelpOfAnUnknownGroupListsTheGroups(): void
    {
        $expected = [2, "", "Error: Unknown option group \"timings\". The groups are forced, ocr, errors, sdh, replace, text, structure, " .
                            "retime, snap, timing, masking, karaoke, ass, and all for every option.\nRun \"subtitle-toolbox help convert\" for the usage.\n"];

        $this->assertSame($expected, self::runApplication(["convert", "--help", "timings"]));
        $this->assertSame($expected, self::runApplication(["help", "convert", "timings"]));
    }


    public function testRetimeHelpMatchesItsOptions(): void
    {
        [$code, $stdout, $stderr] = self::runApplication(["retime", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith(
            "Usage: subtitle-toolbox retime <input>... [--shift SECONDS] [--scale FACTOR]\n" .
            "                               [--from-fps RATE --to-fps RATE] [options]\n\n" .
            "Shifts and scales all cue times, or fits them to a video with another frame\nrate.\n\n" .
            "Pass one or more edits. retime applies them in this order: --shift, --scale,\n--from-fps and --to-fps.",
            $stdout
        );
        $stdout = BinaryTestCase::unwrapHelp($stdout);
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame([
            "shift", "shift-after", "scale", "from-fps", "to-fps", "to", "output", "output-dir", "output-fps",
            "line-ending", "bom", "no-bom", "skip-image-cues", "from", "encoding", "lenient", "input-fps", "fps", "word-timestamps",
            "track", "keep-going", "help",
        ], $matches[1]);
        $this->assertMatchesRegularExpression('/^  --shift-after SECONDS +Shift only the cues that start at this time or later\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --from-fps RATE +Frame rate of the video that the subtitle fits now\. Needs --to-fps\.$/m', $stdout);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps and --output-fps\. Each of them overrides it\.$/m', $stdout);
        $this->assertSame(array_map(fn (Option $option): string => $option->name, (new RetimeCommand())->options()), array_slice($matches[1], 0, -1));
    }


    public function testEveryHelpPageFitsIn80ColumnsWithoutTrailingSpaces(): void
    {
        [, $help] = self::runApplication(["help"]);
        preg_match_all('/^  ([a-z]+) {2,}\S/m', strstr(strstr($help, "Commands:\n"), "\n\n", true), $commands);
        $this->assertCount(11, $commands[1]);

        $pages = ["help" => $help];
        foreach ($commands[1] as $command) {
            $pages["help $command"] = self::runApplication(["help", $command])[1];
        }
        $pages["convert --help all"] = self::runApplication(["convert", "--help", "all"])[1];

        foreach ($pages as $page => $text) {
            $this->assertNotSame("", $text, $page);
            foreach (explode("\n", $text) as $line) {
                $this->assertLessThanOrEqual(80, mb_strlen($line), "$page: $line");
                $this->assertSame(rtrim($line), $line, "$page: trailing space");
            }
        }
    }


    public function testHelpDescribesTheDiffExitCodeAndJson(): void
    {
        $this->assertStringContainsString("\nExit codes:\n" .
                                          "  0  Success.\n" .
                                          "  1  A file broke a validation rule, or diff found a difference.\n" .
                                          "  2  Invalid arguments.\n" .
                                          "  3  A file could not be read or written.\n\n" .
                                          "Options:\n" .
                                          "  -h, --help     Show this help.\n" .
                                          "  -V, --version  Print the version.\n",
                                          self::runApplication(["--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --json +Print the differences as JSON: a list with one object for the pair of files\.$/m', BinaryTestCase::unwrapHelp(self::runApplication(["diff", "--help"])[1]));
        $this->assertMatchesRegularExpression('/^  --json +Print JSON: a list with one object for each input file, also for one file\.$/m',
                                              BinaryTestCase::unwrapHelp(self::runApplication(["info", "--help"])[1]));
    }


    public function testFileErrorsExitWith3(): void
    {
        $this->assertSame([3, "", "stdin: UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                                  "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n"],
                          self::runApplication(["info", "-"], "hello"));
        $this->assertSame([3, "", "stdin: VobSub needs the path of the .idx file. Standard input does not work.\n"],
                          self::runApplication(["info", "-", "--from", "vobsub"], "hello"));
    }


    public function testChaptersAndCloudSpeechJsonNeedFrom(): void
    {
        $chapters = __DIR__ . "/../files/chapters/ffmetadata/real/m4b_audiobook.ffmeta";
        $deepgram = file_get_contents(__DIR__ . "/../files/deepgram/real/pool_utterances_diarize.json");

        $unknown = "UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                   "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n";

        $this->assertSame([3, "", "$chapters: $unknown"], self::runApplication(["info", $chapters]));
        $this->assertSame([3, "", "stdin: $unknown"], self::runApplication(["info", "-"], $deepgram));

        [$code, $stdout] = self::runApplication(["info", $chapters, "--from", "ffmeta-chapters"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("ffmeta-chapters", $stdout);

        [$code, $stdout] = self::runApplication(["info", "-", "--from", "deepgram"], $deepgram);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("deepgram", $stdout);
    }


    public function testOneXChapterFormatNamesStillWorkAsFromAndTo(): void
    {
        $files = __DIR__ . "/../files/chapters/";
        foreach ([
            "ytchapter" => ["youtube/real/video_description.txt", Format::YouTubeChapters],
            "podcast"   => ["podcast/real/spec_basic_example.json", Format::PodcastChapters],
            "ogm"       => ["ogm/real/mkvextract_simple.txt", Format::OgmChapters],
            "ffmeta"    => ["ffmetadata/real/m4b_audiobook.ffmeta", Format::FfMetadataChapters],
        ] as $alias => [$file, $format]) {
            $expected = Subtitle::load($files . $file, $format)->toString($format);
            $this->assertSame([0, $expected, ""], self::runApplication(["convert", $files . $file, "--from", $alias, "--to", $alias]), $alias);
        }

        $this->assertNull(Format::tryFrom("ytchapter"));
    }


    public function testConvertRunsOcrAndFixesCommonErrorsInOneCall(): void
    {
        // The golden file comes from the Latin database. The default database reads one more letter right.
        $latin = __DIR__ . "/../../vendor/yama6a/php-glyph-ocr/resources/Latin.nocr";

        [$code, $stdout, $stderr] = self::runApplication(["convert", self::FILES . "fixing/ocr-en.sup", "--to", "srt", "-o", "-", "--ocr",
                                                          "--ocr-engine", "glyph", "--ocr-database", $latin, "--errors-fix", "--language", "en"]);

        $this->assertSame([0, file_get_contents(self::FILES . "fixing/ocr-en.fixed.srt")], [$code, $stdout]);
        $this->assertStringEndsWith(": OCR 6/6\n", $stderr);
    }


    public function testConvertRunsTextAndTimingEditsInOneCall(): void
    {
        $expected = Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip);
        HearingImpairedRemover::apply($expected, new HearingImpairedOptions());
        $expected->fixOverlaps()->shift(-0.5);

        $this->assertSame([0, $expected->toString(Format::WebVtt), ""],
                          self::runApplication(["convert", self::FILES . "cli/trip.srt", "--sdh", "--timing-fix-overlaps", "--shift", "-0.5", "--to", "vtt", "-o", "-"]));
        $this->assertSame("\u{FEFF}WEBVTT\n\n1\n00:00:00.500 --> 00:00:02.000\n<i>The train leaves at noon.</i>\n\n" .
                          "2\n00:00:02.000 --> 00:00:04.500\nWe need two tickets for the long ride to the coast.\n\n" .
                          "3\n00:00:05.500 --> 00:00:05.900\nToo late.\n", $expected->toString(Format::WebVtt));
    }


    /**
     * Each set: the input file, the convert options and the expected file.
     *
     * @return array<string, array{string, list<string>, string}>
     */
    public static function goldenFiles(): array
    {
        return [
            "SDH"           => ["hearing-impaired/own_sdh.srt", ["--sdh", "--line-ending", "crlf", "--bom"], "hearing-impaired/own_sdh_removed.srt"],
            "shot changes"  => ["shot-changes/own_garden_24fps.srt", ["--snap-shot-changes", self::FILES . "shot-changes/own_ffmpeg_showinfo.log",
                                "--video-fps", "24"], "shot-changes/own_garden_24fps_timed.srt"],
            "timing fixes"  => ["fixes/own_overlaps_and_short_cues.srt", ["--timing-fix-overlaps", "--timing-min-duration", "0.833", "--timing-min-gap", "0.083",
                                "--structure-wrap", "--structure-max-cpl", "42"], "fixes/own_overlaps_and_short_cues_fixed.srt"],
        ];
    }


    /**
     * @param list<string> $options
     */
    #[DataProvider("goldenFiles")]
    public function testConvertEditsMatchTheGoldenFiles(string $input, array $options, string $expected): void
    {
        $this->assertSame([0, file_get_contents(self::FILES . $expected), ""],
                          self::runApplication(["convert", self::FILES . $input, "--to", "srt", "-o", "-", ...$options]));
    }


    public function testConvertRunsAllGroupsInTheFixedOrder(): void
    {
        $words = tempnam(sys_get_temp_dir(), "words");
        file_put_contents($words, "tickets\n");

        $expected = Subtitle::load(self::FILES . "cli/trip.srt", Format::SubRip);
        CommonErrorFixer::apply($expected, new CommonErrorOptions(language: "en"));
        HearingImpairedRemover::apply($expected, new HearingImpairedOptions(parentheses: false));
        $expected->replaceText("Too late", "[late] too late")->stripFormatting()->changeCase(CaseMode::Upper, "en");
        SpeakerLabels::apply($expected, new SpeakerLabelOptions(to: SpeakerStyle::Prefix));
        ProfanityFilter::apply($expected, new ProfanityOptions(["tickets"], ProfanityMask::FirstLetter));
        $expected->wrapLines(20)->removeDuplicateCues()->shift(-0.5)->scale(1.001);
        ShotChangeTiming::apply($expected, new ShotChangeOptions(24));
        $expected->fixOverlaps(0.1)->extendShortCues(1.0, 0.1);
        WordHighlight::apply($expected, new WordHighlightOptions(style: "b"));

        [$code, $stdout, $stderr] = self::runApplication([
            "convert", self::FILES . "cli/trip.srt", "--to", "srt", "-o", "-",
            "--karaoke", "--karaoke-style", "b", "--timing-min-duration", "1", "--timing-fix-overlaps", "--timing-min-gap", "0.1",
            "--snap-min-gap-frames", "2", "--video-fps", "24", "--scale", "1.001", "--shift", "-0.5", "--structure-merge-duplicates", "--structure-wrap", "--structure-max-cpl", "20",
            "--mask-words", $words, "--mask", "first-letter", "--speakers", "prefix", "--case", "upper", "--strip-tags",
            "--replace", "Too late=[late] too late", "--sdh", "--sdh-keep-parentheses", "--language", "en", "--errors-fix",
            "--ocr", "--ocr-engine", "glyph",
        ]);
        unlink($words);

        $this->assertSame([0, $expected->toString(Format::SubRip), ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("\nWE NEED TWO T****** FOR\n", $stdout);
        $this->assertStringContainsString("\n(SIGHS) [LATE]\nTOO LATE.\n", $stdout);
    }


    /**
     * Each set: the arguments, the file that another process creates just before the run creates it, and the files that
     * stay below the working directory, besides the inputs.
     *
     * @return array<string, array{list<string>, string, list<string>}>
     */
    public static function races(): array
    {
        return [
            "second output of a batch" => [["convert", "trip.srt", "shop.vtt", "--to", "vtt", "--output-dir", "out"], "out/shop.vtt", ["out", "out/shop.vtt"]],
            "output file"              => [["convert", "trip.srt", "--to", "vtt", "-o", "new/trip.vtt"], "new/trip.vtt", ["new", "new/trip.vtt"]],
            "mute file"                => [["convert", "radio.vtt", "--to", "srt", "-o", "new/radio.srt", "--mask-words", "words.txt",
                                            "--mute-edl", "new/radio.edl", "--mute-filter", "radio.af"], "radio.af", ["radio.af"]],
            "HLS playlist"             => [["hls", "talk.vtt", "--output-dir", "hls/deep"], "hls/deep/subs.m3u8", ["hls", "hls/deep", "hls/deep/subs.m3u8"]],
        ];
    }


    /**
     * @param list<string> $arguments
     * @param list<string> $left
     */
    #[DataProvider("races")]
    public function testAFileThatAppearsAfterTheCheckStopsTheRunAndRemovesItsFiles(array $arguments, string $racer, array $left): void
    {
        $dir = sys_get_temp_dir() . "/subtitle-toolbox-race-" . bin2hex(random_bytes(6));
        mkdir($dir);
        $inputs = ["trip.srt" => "cli/trip.srt", "shop.vtt" => "cli/shop.vtt", "radio.vtt" => "profanity/radio.vtt",
                   "words.txt" => "profanity/words.txt", "talk.vtt" => "hls/node-webvtt-subs1.vtt"];
        foreach ($inputs as $name => $fixture) {
            copy(self::FILES . $fixture, "$dir/$name");
        }
        $cwd = getcwd();
        chdir($dir);
        $created = [];
        OutputFiles::$beforeCreate = function (string $path) use ($racer, &$created): void {
            $created[] = $path;
            if ($path === $racer) {
                file_put_contents($path, "another process");
            }
        };

        try {
            [$code, , $stderr] = self::runApplication($arguments);
            $files = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                                                    \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
                $files[] = substr($path, strlen("$dir/"));
            }
            sort($files);

            $this->assertSame(3, $code);
            $removed = count($created) - 1;
            $this->assertSame("Error: $racer exists." . match ($removed) {
                0       => "",
                1       => " Removed the file that this run wrote.",
                default => " Removed the $removed files that this run wrote.",
            } . "\n", $stderr);
            $this->assertSame(array_values(array_diff($files, array_keys($inputs))), $left);
            $this->assertSame("another process", file_get_contents("$dir/$racer"));
        } finally {
            OutputFiles::$beforeCreate = null;
            chdir($cwd);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                                                    \RecursiveIteratorIterator::CHILD_FIRST) as $path => $info) {
                $info->isDir() ? rmdir($path) : unlink($path);
            }
            rmdir($dir);
        }
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

        $client   = new FakeHttpClient([[200, file_get_contents(self::TRANSLATION . "deepl_de.json")]]);
        $expected = self::recordedTranslation(new DeepLEngine(new DeepLOptions("key", httpClient: $client)), "en", "de", Format::WebVtt);
        $this->assertSame([0, $expected, ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("\num <b>10:15</b> von Gleis 4 ab.\n", $stdout);
        $this->assertSame([["/v2/translate", "env-key", "DE", "EN"]],
                          array_map(fn (array $request): array => [$request["path"], $request["key"], $request["body"]["target_lang"],
                                                                   $request["body"]["source_lang"]], $this->serverRequests()));
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
            $client   = new FakeHttpClient([[200, file_get_contents(self::TRANSLATION . "google_fr.json")]]);
            $expected = self::recordedTranslation(new GoogleTranslateEngine(new GoogleTranslateOptions("key", httpClient: $client)), "", "fr",
                                                  Format::SubRip);
            $this->assertSame($expected, file_get_contents($output));
        } finally {
            @unlink($output);
        }
        $request = $this->serverRequests()[0];
        $this->assertSame(["/language/translate/v2", "cli-key"], [$request["path"], $request["key"]]);
        $this->assertSame(["target" => "fr", "format" => "html"], array_slice($request["body"], 1));
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateFailsTheFileOnAnHttpErrorWithExitCode3WithoutPrintingTheKey(): void
    {
        $this->startTranslateServer();

        $this->assertSame(
            [3, "", "stdin: TranslationException (Error #109): DeepL rejected the API key (HTTP 403). Check the key and its plan.\n"],
            self::runApplication(["translate", "-", "--engine", "deepl", "--api-key", "forbidden", "--target-language", "de"],
                                 "1\n00:00:01,000 --> 00:00:02,000\nHello\n")
        );
    }


    #[RequiresPhpExtension("curl")]
    public function testTranslateWithKeepGoingGoesOnAfterAFileFailsAtTheService(): void
    {
        $this->startTranslateServer(["DEEPL_API_KEY" => "forbidden"]);
        $first  = self::TRANSLATION . "own_station.srt";
        $second = self::FILES . "cli/latin1.srt";
        $dir    = sys_get_temp_dir() . "/translate-" . bin2hex(random_bytes(6));

        [$code, $stdout, $stderr] = self::runApplication(["translate", $first, $second, "--engine", "deepl", "--target-language", "de",
                                                          "--encoding", "Windows-1252", "--output-dir", $dir, "--keep-going"]);

        $failure = "TranslationException (Error #109): DeepL rejected the API key (HTTP 403). Check the key and its plan.";
        $this->assertSame([3, "2 files: 0 succeeded, 2 failed.\n", "$first: $failure\n$second: $failure\n"], [$code, $stdout, $stderr]);
        $this->assertCount(2, $this->serverRequests());
        $this->assertDirectoryDoesNotExist($dir);
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
        $this->assertSame([2, "", "Error: The API key is empty. Pass --api-key or set DEEPL_API_KEY.$usage"],
                          self::runApplication(["translate", "-", "--engine", "deepl", "--api-key", "", "--target-language", "de"]));
        putenv("DEEPL_API_KEY= ");
        $this->assertSame([2, "", "Error: The API key is empty. Pass --api-key or set DEEPL_API_KEY.$usage"],
                          self::runApplication(["translate", "-", "--engine", "deepl", "--target-language", "de"]));
        $this->assertSame([2, "", "Error: The API key has a control character, or a space at the start or end. Pass the key without them.$usage"],
                          self::runApplication(["translate", "-", "--engine", "google", "--api-key", "key\r", "--target-language", "de"]));
        $this->assertSame([2, "", "Error: 2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE.$usage"],
                          self::runApplication(["translate", self::TRANSLATION . "own_station.srt", self::FILES . "cli/latin1.srt",
                                                "--engine", "google", "--target-language", "fr"]));
    }


    public function testTheTestUrlWorksOnlyForThisMachine(): void
    {
        $cases = [
            "http://127.0.0.1:8080/x?y=1"         => "http://127.0.0.1:8080",
            "https://localhost"                   => "https://localhost",
            "http://[::1]:9000/"                  => "http://[::1]:9000",
            "http://example.com"                  => null,
            "http://127.0.0.1.example.com"        => null,
            "http://localhost@example.com/"       => null,
            "ftp://127.0.0.1"                     => null,
            "127.0.0.1:8080"                      => null,
        ];
        foreach ($cases as $url => $expected) {
            putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL=$url");
            $this->assertSame($expected, TranslateCommand::localTestUrl(), $url);
        }
        putenv("SUBTITLE_TOOLBOX_TRANSLATE_URL");
        $this->assertNull(TranslateCommand::localTestUrl());
    }


    public function testTranslateHelpListsTheOptionsButNoKeyAndNoTestUrl(): void
    {
        putenv("DEEPL_API_KEY=secret-env-key");

        [$code, $stdout, $stderr] = self::runApplication(["translate", "--help"]);

        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("Usage: subtitle-toolbox translate <input>... --engine deepl|google\n" .
                                      "                                  --target-language CODE\n" .
                                      "                                  [--source-language CODE] [--api-key KEY]\n" .
                                      "                                  [options]\n", $stdout);
        preg_match_all('/^  (?:-\w, )?--([\w-]+)/m', $stdout, $matches);
        $this->assertSame(["engine", "api-key", "source-language", "target-language", "to", "output", "output-dir"], array_slice($matches[1], 0, 7));
        $this->assertNotContains("in-place", $matches[1]);
        $this->assertStringNotContainsString("secret-env-key", $stdout);
        $this->assertStringNotContainsString("SUBTITLE_TOOLBOX_TRANSLATE_URL", $stdout);
        $this->assertStringContainsString("  translate  ", self::runApplication(["--help"])[1]);
    }


    public function testWithoutTheCurlExtensionTranslateExitsWith2AndOtherCommandsRun(): void
    {
        $binary = __DIR__ . "/../../bin/subtitle-toolbox";
        $input  = self::TRANSLATION . "own_station.srt";

        $this->assertSame(
            [2, "", "Error: PHP has no ext-curl, which the DeepL and Google engines need. Install the PHP curl extension.\n" .
                    "Run \"subtitle-toolbox help translate\" for the usage.\n"],
            WithoutCurl::run([$binary, "translate", $input, "--engine", "deepl", "--api-key", "key", "--target-language", "de"])
        );
        [$code, $stdout, $stderr] = WithoutCurl::run([$binary, "convert", $input, "--to", "vtt", "--output", "-"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringStartsWith("WEBVTT", ltrim($stdout, "\u{FEFF}"));
    }
}
