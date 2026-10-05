<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\CaseMode;
use SubtitleToolbox\Format;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
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
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChangeTiming;

class ApplicationTest extends TestCase
{
    private const FILES = __DIR__ . "/../files/";

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
        "snap-no-chain" => "the closing of small gaps between cues",
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
        $this->assertContains("check-overlap", $names);
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
        $this->assertStringStartsWith("Usage: subtitle-toolbox convert <input> <output> [options]\n", $stdout);
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
               "  --sdh                       Remove hearing-impaired annotations such as [DOOR SLAMS], (laughs) and JOHN:. A cue with no text left goes.\n";

        $this->assertStringStartsWith($sdh, self::runApplication(["convert", "--help", "sdh"])[1]);
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["convert", "-h", "sdh"]));
        $this->assertSame(self::runApplication(["convert", "--help", "sdh"]), self::runApplication(["help", "convert", "sdh"]));
        $this->assertSame(9, substr_count(self::runApplication(["convert", "--help", "sdh"])[1], "\n"));
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


    public function testHelpDescribesTheDiffExitCodeAndJson(): void
    {
        $this->assertStringContainsString("\nExit codes: 0 success, 1 a file failed, broke a validation rule or differs in diff, 2 invalid arguments.\n",
                                          self::runApplication(["--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --json +Print the differences as one JSON object\.$/m', self::runApplication(["diff", "--help"])[1]);
        $this->assertMatchesRegularExpression('/^  --json +Print JSON: one object for one input file, a list of objects for several\.$/m',
                                              self::runApplication(["info", "--help"])[1]);
    }


    public function testFileErrorsExitWith1(): void
    {
        $this->assertSame([1, "", "stdin: UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                                  "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n"],
                          self::runApplication(["info", "-"], "hello"));
        $this->assertSame([1, "", "stdin: VobSub needs the path of the .idx file. Standard input does not work.\n"],
                          self::runApplication(["info", "-", "--from", "vobsub"], "hello"));
    }


    public function testChaptersAndCloudSpeechJsonNeedFrom(): void
    {
        $chapters = __DIR__ . "/../files/chapters/ffmetadata/real/m4b_audiobook.ffmeta";
        $deepgram = file_get_contents(__DIR__ . "/../files/deepgram/real/pool_utterances_diarize.json");

        $unknown = "UnknownFormatException (Error #106): Format detection found no subtitle format. Pass --from FORMAT. " .
                   "Chapters and cloud speech-to-text JSON always need it, for example --from deepgram.\n";

        $this->assertSame([1, "", "$chapters: $unknown"], self::runApplication(["info", $chapters]));
        $this->assertSame([1, "", "stdin: $unknown"], self::runApplication(["info", "-"], $deepgram));

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
