<?php

namespace SubtitleToolbox\Cli;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

/**
 * Runs bin/subtitle-toolbox as a separate process in a temporary directory with copies of the fixtures.
 */
class BinaryTest extends TestCase
{
    private const BIN = __DIR__ . "/../../bin/subtitle-toolbox";

    private const FIXTURES = __DIR__ . "/../files/cli/";

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
        $this->assertSame(2, $this->runBinary(["validate", "shop.vtt", "--preset", "bbc"])[0]);
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
        $this->assertMatchesRegularExpression('/\AName {7}Extensions +Read  Write\n/', $stdout);
        $this->assertMatchesRegularExpression('/^srt {8}\.srt +yes   yes$/m', $stdout);
        $this->assertMatchesRegularExpression('/^pgs {8}\.sup +yes   yes$/m', $stdout);
        $this->assertCount(count(FormatRegistry::names()) + 1, explode("\n", trim($stdout)));
        $this->assertSame("", $stderr);
    }
}
