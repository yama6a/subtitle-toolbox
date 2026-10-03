<?php

namespace SubtitleToolbox\Cli;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\Formatters\JsonFormatter;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\ResegmentOptions;
use SubtitleToolbox\Subtitle;

/**
 * Runs bin/subtitle-toolbox as a separate process in a temporary directory with copies of the fixtures.
 */
class BinaryTest extends TestCase
{
    private const BIN = __DIR__ . "/../../bin/subtitle-toolbox";

    private const FIXTURES = __DIR__ . "/../files/cli/";

    private const FILES = __DIR__ . "/../files/";

    private const BOM = "\xEF\xBB\xBF";

    private string $dir;


    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . "/subtitle-toolbox-cli-" . bin2hex(random_bytes(6));
        mkdir($this->dir);
        foreach (glob(self::FIXTURES . "*") as $fixture) {
            copy($fixture, "$this->dir/" . basename($fixture));
        }
    }


    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path => $info) {
            $info->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runBinary(array $arguments, string $stdin = ""): array
    {
        $process = proc_open(
            [PHP_BINARY, self::BIN, ...$arguments],
            [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes,
            $this->dir
        );
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }


    private function file(string $name): string
    {
        return file_get_contents("$this->dir/$name");
    }


    private function tripAs(string $formatter): string
    {
        return Subtitle::parse(file_get_contents(self::FIXTURES . "trip.srt"), SubRipParser::class)->format($formatter);
    }


    public function testVersionPrintsTheVersionAlone(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["--version"]);

        $this->assertSame(0, $code);
        $this->assertSame(Version::get() . "\n", $stdout);
        $this->assertMatchesRegularExpression('/^(dev|\d+\.\d+\.\d+\S*)\n$/', $stdout);
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["-V"]));
    }


    public function testWithoutArgumentsPrintsTheHelp(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Usage: subtitle-toolbox <command>", $stdout);
        foreach (["convert", "shift", "scale", "fps", "fix", "strip-sdh", "info", "validate", "formats", "help"] as $command) {
            $this->assertMatchesRegularExpression("/^  $command +\S/m", $stdout);
        }
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["--help"]));
        $this->assertSame([0, $stdout, ""], $this->runBinary(["help"]));
    }


    /**
     * @return array<string, array{string}>
     */
    public static function commands(): array
    {
        return array_combine(
            ["convert", "shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "info", "validate", "formats"],
            array_map(fn (string $command): array => [$command],
                ["convert", "shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "info", "validate", "formats"])
        );
    }


    #[DataProvider("commands")]
    public function testEveryCommandHasHelp(string $command): void
    {
        [$code, $stdout, $stderr] = $this->runBinary([$command, "--help"]);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("Usage: subtitle-toolbox ", $stdout);
        $this->assertStringContainsString("  -h, --help ", $stdout);
        $this->assertSame("", $stderr);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["help", $command]));
        $this->assertSame([0, $stdout, ""], $this->runBinary([$command, "-h"]));
    }


    public function testHelpTextsDescribeWhatTheOptionsDo(): void
    {
        $convert = $this->runBinary(["convert", "--help"])[1];
        $this->assertMatchesRegularExpression('/^  --lenient +Skip or repair broken cues and print a warning for each\. ' .
                                              'SCC, PGS, VobSub and chapter input ignore it\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --encoding NAME +.*A BOM in the input overrides it\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --fps RATE +.*for MicroDVD and iTT output\.$/m', $convert);
        $this->assertStringNotContainsString("SubRip, WebVTT and SBV", $convert);

        $fix = $this->runBinary(["fix", "--help"])[1];
        $this->assertMatchesRegularExpression('/^  --split-long +.*at sentence ends, clause ends or spaces\.$/m', $fix);
        $this->assertMatchesRegularExpression('/^  --merge-short +.*at most 0\.25 s away.*$/m', $fix);
        $this->assertDoesNotMatchRegularExpression('/MicroDVD and iTT output/', $this->runBinary(["info", "--help"])[1]);
    }


    public function testUnknownCommandAndOptionAreUsageErrors(): void
    {
        $this->assertSame([2, "", "Error: Unknown command \"merge\".\nRun \"subtitle-toolbox help\" for the usage.\n"], $this->runBinary(["merge"]));
        $this->assertSame([2, "", "Error: Unknown command \"merge\".\nRun \"subtitle-toolbox help\" for the usage.\n"], $this->runBinary(["help", "merge"]));
        $this->assertSame(
            [2, "", "Error: Unknown option --nope.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--nope"])
        );
        $this->assertSame(
            [2, "", "Error: Unknown format \"docx\". Run \"subtitle-toolbox formats\" for the list.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "trip.srt", "--to", "docx"])
        );
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vobsub"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--from", "txt", "--to", "vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "--to", "vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--line-ending", "cr"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--bom", "--no-bom"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "-o", "a.vtt", "--output-dir", "out"])[0]);
        $this->assertFileDoesNotExist("$this->dir/trip.vtt");
    }


    public function testConvertWithAnOutputFileTakesTheFormatFromItsExtension(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "trip.srt", "trip.vtt"]);

        $this->assertSame([0, "trip.srt -> trip.vtt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($this->tripAs(WebVttFormatter::class), $this->file("trip.vtt"));
    }


    public function testConvertToTsvWritesTabs(): void
    {
        $this->assertSame([0, "trip.srt -> trip.tsv\n", ""], $this->runBinary(["convert", "trip.srt", "trip.tsv"]));
        $tsv = $this->file("trip.tsv");
        $this->assertStringStartsWith(self::BOM . "start\tend\ttext\n00:00:01.000\t", $tsv);
        $this->assertSame(0, substr_count($tsv, ","));

        $this->assertSame([0, "trip.tsv -> trip.csv\n", ""], $this->runBinary(["convert", "trip.tsv", "trip.csv"]));
        $this->assertStringStartsWith(self::BOM . "start,end,text\n00:00:01.000,", $this->file("trip.csv"));
        $this->assertSame(0, substr_count($this->file("trip.csv"), "\t"));
    }


    public function testAnOutputExtensionOfAReadOnlyInputFormatGivesTheWritableFormatOfTheExtension(): void
    {
        copy(__DIR__ . "/../files/whisper/real/openai_whisper_german.json", "$this->dir/lecture.json");

        $this->assertSame([0, "lecture.json -> out.json\n", ""], $this->runBinary(["convert", "lecture.json", "out.json"]));
        $this->assertSame(Subtitle::parse($this->file("lecture.json"))->format(JsonFormatter::class), $this->file("out.json"));
    }


    public function testConvertNeverOverwritesWithoutForce(): void
    {
        file_put_contents("$this->dir/trip.vtt", "old");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "trip.srt", "trip.vtt"]);
        $this->assertSame([1, "", "trip.srt: trip.vtt exists. Pass --force to overwrite it.\n"], [$code, $stdout, $stderr]);
        $this->assertSame("old", $this->file("trip.vtt"));

        $this->assertSame(0, $this->runBinary(["convert", "trip.srt", "trip.vtt", "--force"])[0]);
        $this->assertSame($this->tripAs(WebVttFormatter::class), $this->file("trip.vtt"));

        [$code, , $stderr] = $this->runBinary(["convert", "trip.srt", "--to", "srt"]);
        $this->assertSame([1, "trip.srt: The output trip.srt is the input file. Pass --force to overwrite it.\n"], [$code, $stderr]);
    }


    public function testConvertWritesNextToTheInputByDefault(): void
    {
        mkdir("$this->dir/season1");
        rename("$this->dir/trip.srt", "$this->dir/season1/trip.srt");

        $this->assertSame([0, "season1/trip.srt -> season1/trip.vtt\n", ""], $this->runBinary(["convert", "season1/trip.srt", "--to", "vtt"]));
        $this->assertFileExists("$this->dir/season1/trip.vtt");
    }


    public function testBatchGoesOnWithKeepGoingAndPrintsASummary(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "*.srt", "--to", "vtt", "--output-dir", "out", "--keep-going"]);

        $this->assertSame(1, $code);
        $this->assertSame("latin1.srt -> out/latin1.vtt\ntrip.srt -> out/trip.vtt\n3 files: 2 succeeded, 1 failed.\n", $stdout);
        $this->assertSame("broken.srt: ParsingException (Error #100): Block #1 doesn't seem to have its timestamps on its second line!\n", $stderr);
        $this->assertSame($this->tripAs(WebVttFormatter::class), $this->file("out/trip.vtt"));
        $this->assertFileDoesNotExist("$this->dir/out/broken.vtt");
    }


    public function testBatchStopsAtTheFirstFailureWithoutKeepGoing(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "broken.srt", "trip.srt", "shop.vtt", "--to", "srt", "--output-dir", "out"]);

        $this->assertSame(1, $code);
        $this->assertSame("3 files: 0 succeeded, 1 failed, 2 skipped.\n", $stdout);
        $this->assertStringEndsWith("Stopped at the first failure. Pass --keep-going to process the other files.\n", $stderr);
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    public function testConvertReadsTheSubtitleFilesOfADirectory(): void
    {
        mkdir("$this->dir/in");
        foreach (["trip.srt", "shop.vtt", "notes.txt"] as $name) {
            copy(self::FIXTURES . $name, "$this->dir/in/$name");
        }

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "in", "--to", "srt", "--output-dir", "out", "--force"]);

        $this->assertSame([0, "in/shop.vtt -> out/shop.srt\nin/trip.srt -> out/trip.srt\n2 files: 2 succeeded, 0 failed.\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($this->tripAs(SubRipFormatter::class), $this->file("out/trip.srt"));
    }


    public function testDashReadsStandardInputAndWritesStandardOutput(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "-", "--to", "vtt"], file_get_contents(self::FIXTURES . "trip.srt"));

        $this->assertSame([0, $this->tripAs(WebVttFormatter::class), ""], [$code, $stdout, $stderr]);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "trip.srt", "-o", "-", "--to", "vtt"]));
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "-", "--from", "srt", "--to", "vtt"], $this->file("trip.srt")));
    }


    public function testTheContentSetsTheInputFormatBeforeTheExtension(): void
    {
        rename("$this->dir/trip.srt", "$this->dir/trip.txt");

        $this->assertSame([0, $this->tripAs(WebVttFormatter::class), ""], $this->runBinary(["convert", "trip.txt", "--to", "vtt", "-o", "-"]));
        $this->assertSame(
            [1, "", "notes.txt: The format is unknown. Pass --from.\n"],
            $this->runBinary(["convert", "notes.txt", "--to", "vtt"])
        );
        $this->assertSame([1, "", "missing.srt: The file does not exist.\n"], $this->runBinary(["convert", "missing.srt", "--to", "vtt"]));
    }


    public function testLenientEncodingLineEndingAndBomOptions(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "broken.srt", "--to", "srt", "-o", "-", "--lenient", "--line-ending", "crlf", "--no-bom"]);

        $this->assertSame(0, $code);
        $this->assertSame("1\r\n00:00:01,000 --> 00:00:02,000\r\nFirst cue.\r\n\r\n2\r\n00:00:05,000 --> 00:00:06,000\r\nLast cue.\r\n", $stdout);
        $this->assertSame("broken.srt: line 5: Block #1 doesn't seem to have its timestamps on its second line! (skipped)\n", $stderr);

        [$code, $stdout] = $this->runBinary(["convert", "latin1.srt", "--encoding", "Windows-1252", "--to", "vtt", "-o", "-"]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith(self::BOM . "WEBVTT\n", $stdout);
        $this->assertStringContainsString("Caf\u{e9} au lait.", $stdout);

        [$code, $stdout] = $this->runBinary(["convert", "shop.vtt", "--to", "sbv", "-o", "-", "--bom"]);
        $this->assertSame(0, $code);
        $this->assertStringStartsWith(self::BOM . "0:00:10.000,0:00:12.000\n", $stdout);
    }


    public function testStripTags(): void
    {
        [$code, $stdout] = $this->runBinary(["convert", "trip.srt", "--to", "srt", "-o", "-", "--strip-tags"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nThe train leaves at noon.\n", $stdout);
    }


    public function testForcedOnly(): void
    {
        copy(__DIR__ . "/../files/forced/forced_signs_2398.itt", "$this->dir/signs.itt");
        $expected = Subtitle::parse($this->file("signs.itt"))->forcedOnly()->format(SubRipFormatter::class);

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "signs.itt", "signs.srt", "--forced-only"]);

        $this->assertSame([0, "signs.itt -> signs.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($expected, $this->file("signs.srt"));
        $this->assertSame(3, substr_count($expected, " --> "));
        $this->assertSame(6, substr_count($this->runBinary(["convert", "signs.itt", "--to", "srt", "-o", "-"])[1], " --> "));
    }


    public function testSpeakers(): void
    {
        $files = __DIR__ . "/../files/speakers/";
        copy($files . "voices.vtt", "$this->dir/voices.vtt");
        copy($files . "sdh_labels.srt", "$this->dir/labels.srt");

        foreach (["prefix", "dashes", "colours"] as $mode) {
            $this->assertSame(
                [0, file_get_contents($files . "voices_$mode.srt"), ""],
                $this->runBinary(["convert", "voices.vtt", "--to", "srt", "-o", "-", "--no-bom", "--speakers", $mode])
            );
        }
        $this->assertSame(
            [0, file_get_contents($files . "sdh_labels_voices.vtt"), ""],
            $this->runBinary(["convert", "labels.srt", "--to", "vtt", "-o", "-", "--no-bom", "--speakers", "from-prefix"])
        );
        $this->assertSame(
            [2, "", "Error: Unknown speaker mode \"names\". Known modes: prefix, dashes, colours, from-prefix.\n" .
                    "Run \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "voices.vtt", "--to", "srt", "--speakers", "names"])
        );
    }


    public function testMaskWords(): void
    {
        $files = __DIR__ . "/../files/profanity/";
        copy($files . "keys.srt", "$this->dir/keys.srt");
        copy($files . "words.txt", "$this->dir/words.txt");
        $masked = function (string $mask): string {
            $subtitle = Subtitle::parse($this->file("keys.srt"));
            ProfanityFilter::apply($subtitle, new ProfanityOptions(mask: $mask, wordFile: "$this->dir/words.txt"));

            return $subtitle->format(SubRipFormatter::class);
        };

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt"]);
        $this->assertSame([0, $masked(ProfanityOptions::MASK_STARS), ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("- Go to ****.\n", $stdout);

        $this->assertSame(
            [0, $masked(ProfanityOptions::MASK_FIRST_LETTER), ""],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "first-letter"])
        );
        $this->assertSame(
            [0, $masked(ProfanityOptions::MASK_REMOVE), ""],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "-o", "-", "--mask-words", "words.txt", "--mask", "remove"])
        );
        $this->assertSame(2, $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask-words", "words.txt", "--mask", "beep"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask", "stars"])[0]);
        $this->assertSame(
            [2, "", "Error: Cannot read the word file missing.txt.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
            $this->runBinary(["convert", "keys.srt", "--to", "srt", "--mask-words", "missing.txt"])
        );
    }


    public function testMicroDvdNeedsTheFrameRate(): void
    {
        [$code, , $stderr] = $this->runBinary(["convert", "frames.sub", "--to", "srt", "-o", "-"]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("The frame rate is unknown.", $stderr);

        [$code, $stdout] = $this->runBinary(["convert", "frames.sub", "--to", "srt", "-o", "-", "--fps", "25"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:03,000\nHello from the frames.\n", $stdout);

        $this->assertSame([0, "{25}{75}Hello from the frames.\n{100}{150}{y:i}Second line.\n", ""],
                          $this->runBinary(["shift", "frames.sub", "--by", "0", "--fps", "25"]));

        $this->assertSame([1, "", "trip.srt: MicroDVD output needs the frame rate of the video. Pass --fps.\n"],
                          $this->runBinary(["convert", "trip.srt", "--to", "microdvd", "-o", "-"]));
        $this->assertSame(0, $this->runBinary(["convert", "trip.srt", "trip.sub", "--fps", "23.976"])[0]);
        $this->assertStringStartsWith("{24}{72}", $this->file("trip.sub"));
    }


    public function testShiftWritesOneInputToStandardOutput(): void
    {
        $expected = Subtitle::parse($this->file("trip.srt"), SubRipParser::class)->shift(-1.5)->format(SubRipFormatter::class);

        $this->assertSame([0, $expected, ""], $this->runBinary(["shift", "trip.srt", "--by", "-1.5"]));
        $this->assertSame([0, $expected, ""], $this->runBinary(["shift", "trip.srt", "--by=-1.5"]));
        $this->assertSame([0, "trip.srt -> shifted.srt\n", ""], $this->runBinary(["shift", "trip.srt", "--by", "-1.5", "-o", "shifted.srt"]));
        $this->assertSame($expected, $this->file("shifted.srt"));
        $this->assertSame(2, $this->runBinary(["shift", "trip.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["shift", "trip.srt", "--by", "soon"])[0]);
    }


    public function testShiftAfter(): void
    {
        [$code, $stdout] = $this->runBinary(["shift", "shop.vtt", "--by", "5", "--after", "12"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:10.000 --> 00:00:12.000", $stdout);
        $this->assertStringContainsString("00:00:18.000 --> 00:00:20.500", $stdout);
    }


    public function testInPlaceRetimesSeveralFiles(): void
    {
        $this->assertSame(
            [2, "", "Error: Pass --output-dir or --in-place for several input files.\nRun \"subtitle-toolbox help shift\" for the usage.\n"],
            $this->runBinary(["shift", "trip.srt", "shop.vtt", "--by", "1"])
        );
        $this->assertSame(2, $this->runBinary(["shift", "trip.srt", "shop.vtt", "--by", "1", "-o", "out.srt"])[0]);

        [$code, $stdout, $stderr] = $this->runBinary(["shift", "trip.srt", "shop.vtt", "--by", "1", "--in-place"]);

        $this->assertSame([0, "trip.srt -> trip.srt\nshop.vtt -> shop.vtt\n2 files: 2 succeeded, 0 failed.\n", ""], [$code, $stdout, $stderr]);
        $this->assertStringContainsString("00:00:11.000 --> 00:00:13.000", $this->file("shop.vtt"));
        $this->assertStringContainsString("00:00:02,000 --> 00:00:04,000", $this->file("trip.srt"));
    }


    public function testScale(): void
    {
        [$code, $stdout] = $this->runBinary(["scale", "shop.vtt", "--factor", "2"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:20.000 --> 00:00:24.000", $stdout);
        $this->assertSame(2, $this->runBinary(["scale", "shop.vtt", "--factor", "0"])[0]);
    }


    public function testFpsAndItsAlias(): void
    {
        $expected = Subtitle::parse($this->file("shop.vtt"))->convertFrameRate(25, 23.976)->format(WebVttFormatter::class);

        $this->assertSame([0, $expected, ""], $this->runBinary(["fps", "shop.vtt", "--from", "25", "--to", "23.976"]));
        $this->assertSame([0, $expected, ""], $this->runBinary(["sync-fps", "shop.vtt", "--from", "25", "--to", "23.976"]));
        $this->assertSame(2, $this->runBinary(["fps", "shop.vtt", "--from", "25"])[0]);
        $this->assertSame(2, $this->runBinary(["fps", "shop.vtt", "--from", "vtt", "--to", "srt"])[0]);
    }


    public function testFix(): void
    {
        [$code, $stdout] = $this->runBinary(["fix", "trip.srt", "--overlaps", "--min-gap", "0.1", "--min-duration", "1", "--wrap", "30"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("00:00:01,000 --> 00:00:02,400\n", $stdout);
        $this->assertStringContainsString("[BELL RINGS] ANNA: We need two tickets\nfor the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("00:00:06,000 --> 00:00:07,000\n", $stdout);
        $this->assertSame(2, $this->runBinary(["fix", "trip.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["fix", "trip.srt", "--wrap", "0"])[0]);
    }


    public function testFixMergeShort(): void
    {
        copy(__DIR__ . "/../files/short-cues/own_speech_to_text.srt", "$this->dir/speech.srt");
        $narrow = Subtitle::parse($this->file("speech.srt"))
            ->mergeShortCues(new MergeShortCuesOptions(maxCharactersPerLine: 20, maxLines: 3))
            ->format(SubRipFormatter::class);

        $this->assertSame(
            [0, file_get_contents(__DIR__ . "/../files/short-cues/own_speech_to_text_merged.srt"), ""],
            $this->runBinary(["fix", "speech.srt", "--merge-short"])
        );
        $this->assertSame([0, $narrow, ""], $this->runBinary(["fix", "speech.srt", "--merge-short", "--max-cpl", "20", "--max-lines", "3"]));
        $this->assertSame(2, $this->runBinary(["fix", "speech.srt", "--merge-short", "--max-cpl", "0"])[0]);
    }


    public function testFixSplitLong(): void
    {
        copy(__DIR__ . "/../files/resegmenting/own_whisper_long_segments.json", "$this->dir/whisper.json");
        $split = fn (ResegmentOptions $options): string =>
            Subtitle::parse($this->file("whisper.json"))->splitLongCues($options)->format(WebVttFormatter::class);

        [$code, $stdout, $stderr] = $this->runBinary(["fix", "whisper.json", "--split-long", "--to", "vtt"]);

        $this->assertSame([0, $split(new ResegmentOptions()), ""], [$code, $stdout, $stderr]);
        $this->assertGreaterThan(count(Subtitle::parse($this->file("whisper.json"))->getCues()), substr_count($stdout, " --> "));
        $this->assertSame(
            [0, $split(new ResegmentOptions(maxCharactersPerLine: 30, maxLines: 1)), ""],
            $this->runBinary(["fix", "whisper.json", "--split-long", "--max-cpl", "30", "--max-lines", "1", "--to", "vtt"])
        );
    }


    public function testFixCommonErrors(): void
    {
        copy(self::FILES . "fixing/web-errors.srt", "$this->dir/web.srt");
        copy(self::FILES . "vobsub/text-pal.ocr.srt", "$this->dir/pal.srt");
        copy(self::FILES . "fixing/user_OCRFixReplaceList.xml", "$this->dir/list.xml");

        [$code, $stdout, $stderr] = $this->runBinary(["fix", "web.srt", "--common-errors", "--language", "en", "--line-ending", "crlf"]);
        $this->assertSame([0, ""], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "fixing/web-errors.fixed.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runBinary(["fix", "pal.srt", "--common-errors", "--language", "en", "--replace-list", "list.xml", "--list-fixes"]);
        $this->assertSame(0, $code);
        $this->assertStringEqualsFile(self::FILES . "fixing/text-pal.fixed.srt", $stdout);
        $this->assertStringStartsWith("pal.srt: cue 1: ", $stderr);
        $this->assertStringContainsString(": replaceList: ", $stderr);

        $this->assertSame([2, "", "Error: Pass --common-errors with --language.\nRun \"subtitle-toolbox help fix\" for the usage.\n"],
                          $this->runBinary(["fix", "web.srt", "--overlaps", "--language", "en"]));
        $this->assertSame(2, $this->runBinary(["fix", "web.srt", "--common-errors", "--replace-list", "missing.xml"])[0]);
    }


    public function testStripSdh(): void
    {
        [$code, $stdout] = $this->runBinary(["strip-sdh", "trip.srt", "--to", "vtt"]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nWe need two tickets for the long ride to the coast.\n", $stdout);
        $this->assertStringContainsString("\nToo late.\n", $stdout);
        $this->assertStringNotContainsString("BELL", $stdout);

        [, $stdout] = $this->runBinary(["strip-sdh", "trip.srt", "--keep-parentheses", "--keep-speaker-labels"]);
        $this->assertStringContainsString("\nANNA: We need", $stdout);
        $this->assertStringContainsString("\n(sighs) Too late.\n", $stdout);
        $this->assertSame(2, $this->runBinary(["strip-sdh", "trip.srt", "--brackets", "{"])[0]);
    }


    public function testInfoAsText(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["info", "trip.srt"]);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("trip.srt\n  Format:                srt\n  Cues:                  3\n", $stdout);
        $this->assertStringContainsString("  Gap:                   min -0.5, average 0.25, max 1 s\n", $stdout);
        $this->assertSame("", $stderr);
    }


    public function testInfoAsJson(): void
    {
        [$code, $stdout] = $this->runBinary(["info", "trip.srt", "--json"]);
        $info = json_decode($stdout, true);

        $this->assertSame(0, $code);
        $this->assertSame("trip.srt", $info["file"]);
        $this->assertSame("srt", $info["format"]);
        $this->assertSame(3, $info["statistics"]["cueCount"]);
        $this->assertSame(3, $info["statistics"]["mostUsedWords"]["the"]);

        [$code, $stdout, $stderr] = $this->runBinary(["info", "trip.srt", "shop.vtt", "broken.srt", "--json", "--keep-going"]);
        $list = json_decode($stdout, true);

        $this->assertSame(1, $code);
        $this->assertSame(["trip.srt", "shop.vtt"], array_column($list, "file"));
        $this->assertSame("vtt", $list[1]["format"]);
        $this->assertStringStartsWith("broken.srt: ", $stderr);
        $this->assertStringEndsWith("3 files: 2 succeeded, 1 failed.\n", $stderr);
    }


    public function testValidateWithThePreset(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["validate", "trip.srt", "--preset", "netflix-en"]);

        $this->assertSame(1, $code);
        $this->assertSame(
            "trip.srt: cue 2: maxCharactersPerLine 57, limit 42\n" .
            "trip.srt: cue 2: maxCharactersPerSecond 27.6, limit 20\n" .
            "trip.srt: cue 2: noOverlap 0.5\n" .
            "trip.srt: cue 3: maxCharactersPerSecond 42.5, limit 20\n" .
            "trip.srt: cue 3: minDuration 0.4, limit 0.833\n",
            $stdout
        );
        $this->assertSame("", $stderr);

        $this->assertSame(
            [1, "trip.srt: cue 2: noOverlap 0.5\nshop.vtt: no problems\n2 files: 1 valid, 1 with problems, 0 failed.\n", ""],
            $this->runBinary(["validate", "trip.srt", "shop.vtt", "--no-overlap"])
        );
        $this->assertSame([0, "shop.vtt: no problems\n", ""], $this->runBinary(["validate", "shop.vtt", "--preset", "netflix-en", "--max-cps", "30"]));
        $this->assertSame(2, $this->runBinary(["validate", "shop.vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["validate", "shop.vtt", "--preset", "nope"])[0]);
    }


    public function testValidateWithTheBbcPreset(): void
    {
        $this->assertSame([
            1,
            "trip.srt: cue 2: maxCharactersPerLine 57, limit 37\n" .
            "trip.srt: cue 2: maxWordsPerMinute 336, limit 180\n" .
            "trip.srt: cue 2: minSecondsPerWord 0.179, limit 0.3\n" .
            "trip.srt: cue 3: maxWordsPerMinute 450, limit 180\n" .
            "trip.srt: cue 3: minSecondsPerWord 0.133, limit 0.3\n",
            "",
        ], $this->runBinary(["validate", "trip.srt", "--preset", "bbc"]));
        $this->assertSame(
            [1, "trip.srt: cue 2: maxCharactersPerLine 57, limit 37\n", ""],
            $this->runBinary(["validate", "trip.srt", "--preset", "bbc", "--max-wpm", "500", "--min-seconds-per-word", "0.1"])
        );
    }


    public function testValidateTextRules(): void
    {
        $vtt = "WEBVTT\n\n" .
               "00:00:01.000 --> 00:00:04.000\n-Where is the bus?\n- At the <i>corner.\n\n" .
               "00:00:05.000 --> 00:00:08.000\nTHE BUS IS LATE\nWait <i> here</i>\n\n" .
               "00:00:09.000 --> 00:00:12.000\n<v Anna>Rain today.\n<v Ben>Sun tomorrow.\n<v Cleo>Snow later.\n\n" .
               "00:00:13.000 --> 00:00:15.000\n&nbsp;Café open.\n";

        $this->assertSame([
            1,
            "stdin: cue 1: noUnbalancedTags 1\n" .
            "stdin: cue 1: dialogueDashStyle 1\n" .
            "stdin: cue 2: noDoubleSpaces 1\n" .
            "stdin: cue 2: noAllCapsLines 1\n" .
            "stdin: cue 3: maxSpeakersPerCue 3, limit 2\n" .
            "stdin: cue 4: noLeadingOrTrailingSpaces 1\n" .
            "stdin: cue 4: allowedCharacters 2\n",
            "",
        ], $this->runBinary([
            "validate", "-", "--dialogue-dash", "- ", "--no-unbalanced-tags", "--no-all-caps-lines", "--no-double-spaces",
            "--no-leading-or-trailing-spaces", "--max-speakers", "2", "--allowed-characters", "[A-Za-z0-9 .,!?<>/\\-]",
        ], $vtt));
        $this->assertSame(
            [2, "", "Error: The dialogue dash style must be a hyphen, an en dash or an em dash, with or without one space after it, got \"x\".\n" .
                    "Run \"subtitle-toolbox help validate\" for the usage.\n"],
            $this->runBinary(["validate", "-", "--dialogue-dash", "x"], $vtt)
        );
        $this->assertSame(2, $this->runBinary(["validate", "-", "--allowed-characters", "[z-a]"], $vtt)[0]);
    }


    public function testValidateAsJson(): void
    {
        [$code, $stdout] = $this->runBinary(["validate", "trip.srt", "--max-cpl", "42", "--json"]);

        $this->assertSame(1, $code);
        $this->assertSame([
            "file"    => "trip.srt",
            "format"  => "srt",
            "valid"   => false,
            "results" => [["cueIndex" => 1, "cueNumber" => 2, "rule" => "maxCharactersPerLine", "value" => 57, "limit" => 42]],
        ], json_decode($stdout, true));
    }


    public function testConvertReadsATrackOfAnMkvFile(): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        copy(self::FILES . "mkv/seek_head.mkv", "$this->dir/one.webm");
        $mkv = MatroskaReader::open(self::FILES . "mkv/text_tracks.mkv");

        $this->assertSame([0, "movie.mkv -> movie.srt\n", ""], $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "3"]));
        $this->assertSame($mkv->extract(3)->format(SubRipFormatter::class), $this->file("movie.srt"));

        [$code, $stdout] = $this->runBinary(["convert", "-", "--to", "vtt", "--track", "5"], $this->file("movie.mkv"));
        $this->assertSame([0, $mkv->extract(5)->format(WebVttFormatter::class)], [$code, $stdout]);

        $this->assertSame([0, "one.webm -> one.vtt\n", ""], $this->runBinary(["convert", "one.webm", "one.vtt"]));
        $this->assertSame(MatroskaReader::open(self::FILES . "mkv/seek_head.mkv")->extract(2)->format(WebVttFormatter::class),
                          $this->file("one.vtt"));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "movie.mkv", "--to", "srt", "-o", "-"]);
        $this->assertSame([1, ""], [$code, $stdout]);
        $this->assertStringStartsWith("movie.mkv: The file has 6 subtitle tracks. Pass --track with one of them:\n" .
                                      "  3: S_TEXT/UTF8, de, \"Deutsch (Forced)\", forced\n  4: S_TEXT/ASS, eng, \"English\", default\n", $stderr);
        $this->assertSame(1, $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "7", "-o", "-"])[0]);
        $this->assertSame([1, "", "trip.srt: --track needs an MKV or WebM input.\n"],
                          $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--track", "3", "-o", "-"]));
        $this->assertSame(2, $this->runBinary(["convert", "movie.mkv", "--to", "srt", "--track", "x"])[0]);
    }


    public function testInfoListsTheTracksOfAnMkvFile(): void
    {
        copy(self::FILES . "mkv/pgs.mkv", "$this->dir/pgs.mkv");

        $this->assertSame(
            [0, "pgs.mkv\n  Format: matroska\n  Track 3: S_HDMV/PGS, ger, default\n  Track 4: S_HDMV/PGS, eng, default, forced\n", ""],
            $this->runBinary(["info", "pgs.mkv"])
        );

        [$code, $stdout] = $this->runBinary(["info", "pgs.mkv", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame(["file" => "pgs.mkv", "format" => "matroska", "tracks" => [
            ["number" => 3, "codecId" => "S_HDMV/PGS", "language" => "ger", "name" => null, "default" => true, "forced" => false],
            ["number" => 4, "codecId" => "S_HDMV/PGS", "language" => "eng", "name" => null, "default" => true, "forced" => true],
        ]], json_decode($stdout, true));

        [$code, $stdout] = $this->runBinary(["info", "pgs.mkv", "--track", "4"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("  Format:", $stdout);
        $this->assertMatchesRegularExpression('/^  Image cues: +\d+, 0 with text$/m', $stdout);
    }


    public function testImageFormats(): void
    {
        mkdir("$this->dir/disc");
        copy(__DIR__ . "/../files/pgs/shapes_576p.sup", "$this->dir/disc/shapes.sup");
        copy(__DIR__ . "/../files/vobsub/two-tracks-pal.idx", "$this->dir/disc/tracks.idx");
        copy(__DIR__ . "/../files/vobsub/two-tracks-pal.sub", "$this->dir/disc/tracks.sub");

        $this->assertSame(
            [1, "", "disc/shapes.sup: The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.\n"],
            $this->runBinary(["convert", "disc/shapes.sup", "--to", "srt"])
        );
        $this->assertSame([0, "disc/shapes.sup -> out/shapes.srt\n", ""], $this->runBinary(["convert", "disc/shapes.sup", "--to", "srt", "--output-dir", "out", "--skip-image-cues"]));

        [$code, $stdout] = $this->runBinary(["info", "disc", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame(["disc/shapes.sup", "disc/tracks.idx"], array_column(json_decode($stdout, true), "file"));
        $this->assertSame(["pgs", "vobsub"], array_column(json_decode($stdout, true), "format"));
        $this->assertSame(5, json_decode($stdout, true)[1]["statistics"]["cueCount"]);
    }


    public function testOcrReadsTheImageCuesOfTheTextFixtures(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(__DIR__ . "/../files/vobsub/text-pal.idx", "$this->dir/text.idx");
        copy(__DIR__ . "/../files/vobsub/text-pal.sub", "$this->dir/text.sub");

        $this->assertSame([0, "text.sup -> text.srt\n", "text.sup: OCR 12/12\n"], $this->runBinary(["convert", "text.sup", "text.srt", "--ocr"]));
        $this->assertFileEquals(__DIR__ . "/../files/pgs/text_1080p.ocr.srt", "$this->dir/text.srt");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "text.idx", "--to", "srt", "--output", "-", "--ocr"]);
        $this->assertSame([0, "text.idx: OCR 6/6\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(__DIR__ . "/../files/vobsub/text-pal.ocr.srt", $stdout);
    }


    public function testOcrDatabaseReplacesTheLatinDatabase(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        (new GlyphDatabase())->save("$this->dir/empty.nocr");

        [$code, $stdout] = $this->runBinary(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-database", "empty.nocr"]);

        $this->assertSame(0, $code);
        $subtitle = Subtitle::parse($stdout);
        $this->assertCount(12, $subtitle->getCues());
        foreach ($subtitle->getCues() as $cue) {
            $this->assertMatchesRegularExpression("/^\\*+( \\*+)*$/", implode(" ", $cue->getLines()));
        }
    }


    public function testOcrOptionErrors(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        file_put_contents("$this->dir/broken.nocr", "no database");

        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-database.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr-database", "broken.nocr"]));
        $this->assertSame([2, "", "Error: Cannot read the glyph database - the data is not gzip-compressed!\n" .
                                  "Run \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr", "--ocr-database", "broken.nocr"]));
        $this->assertFileDoesNotExist("$this->dir/text.srt");
    }


    public function testOcrLeavesFilesWithoutImageCuesAsTheyAre(): void
    {
        $this->assertSame([0, "trip.srt -> trip.vtt\n", ""], $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--ocr"]));
        $this->assertSame(Subtitle::parse($this->file("trip.srt"))->format(WebVttFormatter::class), $this->file("trip.vtt"));
    }


    public function testInfoCountsImageCuesAndImageCuesWithText(): void
    {
        copy(__DIR__ . "/../files/vobsub/text-pal.idx", "$this->dir/text.idx");
        copy(__DIR__ . "/../files/vobsub/text-pal.sub", "$this->dir/text.sub");
        $this->assertSame(0, $this->runBinary(["convert", "text.idx", "text.json", "--ocr"])[0]);

        [$code, $stdout] = $this->runBinary(["info", "text.idx"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\n  Cues:                  6\n  Image cues:            6, 0 with text\n", $stdout);

        [, $stdout] = $this->runBinary(["info", "text.json"]);
        $this->assertStringContainsString("\n  Cues:                  6\n  Image cues:            6, 6 with text\n", $stdout);

        [$code, $stdout] = $this->runBinary(["info", "text.idx", "text.json", "trip.srt", "--json"]);
        $this->assertSame(0, $code);
        $this->assertSame([["count" => 6, "withText" => 0], ["count" => 6, "withText" => 6], ["count" => 0, "withText" => 0]],
                          array_column(json_decode($stdout, true), "imageCues"));

        [, $stdout] = $this->runBinary(["info", "trip.srt"]);
        $this->assertStringNotContainsString("Image cues", $stdout);
    }


    public function testFormatsListsTheRegistry(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["formats"]);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\AName +Extensions +Read  Write\n/', $stdout);
        $this->assertMatchesRegularExpression('/^srt +\.srt +yes   yes$/m', $stdout);
        $this->assertMatchesRegularExpression('/^pgs +\.sup +yes   yes$/m', $stdout);
        $this->assertCount(count(FormatRegistry::names()) + 1, explode("\n", trim($stdout)));
        $this->assertSame("", $stderr);
    }
}
