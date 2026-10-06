<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Format;
use SubtitleToolbox\Tests\Support\BinaryTestCase;

/**
 * Runs the commands that every run shares: help, version, usage errors, exit codes and the formats list.
 */
class BinaryGeneralTest extends BinaryTestCase
{
    private const COMMANDS = ["convert", "retime", "info", "validate", "sync", "diff", "dual", "hls", "formats"];


    public function testPhpWarningGoesToStandardErrorAndKeepsTheOutputClean(): void
    {
        file_put_contents(
            "$this->dir/warn.php",
            "<?php register_shutdown_function(static fn () => trigger_error(\"test warning 266\", E_USER_WARNING));\n"
        );
        $prepend = ["-d", "auto_prepend_file=$this->dir/warn.php", "-d", "log_errors=0"];
        $arguments = ["convert", "trip.srt", "--to", "vtt", "-o", "-"];

        [$code, $stdout, $stderr] = $this->runBinary($arguments, "", "-d", "display_errors=1", ...$prepend);
        $this->assertSame([0, $this->tripAs(Format::WebVtt)], [$code, $stdout]);
        $this->assertStringContainsString("test warning 266", $stderr);

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runBinary($arguments, "", "-d", "display_errors=0", ...$prepend));
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
        foreach ([...self::COMMANDS, "help"] as $command) {
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
            self::COMMANDS,
            array_map(fn (string $command): array => [$command], self::COMMANDS)
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
        $this->assertMatchesRegularExpression('/^  --input-fps RATE +Frame rate of a MicroDVD input without a \{1\}\{1\}<fps> first line, and of CSV or TSV times in hh:mm:ss:ff\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --output-fps RATE +Frame rate of MicroDVD and iTT output\..*$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps, --output-fps and --video-fps\. Each of them overrides it\.$/m', $convert);
        $this->assertMatchesRegularExpression('/^  --from FORMAT +Input format\./m', $convert);
        $this->assertMatchesRegularExpression('/^  --to FORMAT +Output format\./m', $convert);
        $this->assertStringNotContainsString("SubRip, WebVTT and SBV", $convert);

        $structure = $this->runBinary(["convert", "--help", "structure"])[1];
        $this->assertMatchesRegularExpression('/^  --structure-split-long +.*at sentence ends, clause ends or spaces\.$/m', $structure);
        $this->assertMatchesRegularExpression('/^  --structure-merge-short +.*at most 0\.25 s away.*$/m', $structure);
        $info = $this->runBinary(["info", "--help"])[1];
        $this->assertDoesNotMatchRegularExpression('/--output-fps|--video-fps/', $info);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Same as --input-fps\.$/m', $info);

        $validate = $this->runBinary(["validate", "--help"])[1];
        $this->assertMatchesRegularExpression('/^  --video-fps RATE +Frame rate of the video, for the 2-frame gap of netflix-en\. Default: 23\.976\.$/m', $validate);
        $this->assertMatchesRegularExpression('/^  --fps RATE +Sets --input-fps and --video-fps\. Each of them overrides it\.$/m', $validate);
        $this->assertDoesNotMatchRegularExpression('/--output-fps/', $validate);
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
        $this->assertSame(2, $this->runBinary(["convert", "--to", "vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--line-ending", "cr"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--bom", "--no-bom"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "trip.srt", "--to", "vtt", "-o", "a.vtt", "--output-dir", "out"])[0]);
        $this->assertFileDoesNotExist("$this->dir/trip.vtt");
    }


    public function testExitCodesTellResultsUsageErrorsAndFileErrorsApart(): void
    {
        file_put_contents("$this->dir/blocker", "a file, not a directory");
        mkdir("$this->dir/empty");
        $usage = fn (string $command): string => "\nRun \"subtitle-toolbox help $command\" for the usage.\n";

        $this->assertSame(1, $this->runBinary(["validate", "trip.srt", "--max-cpl", "20"])[0]);
        $this->assertSame(1, $this->runBinary(["diff", "trip.srt", "shop.vtt"])[0]);

        $this->assertSame([2, "", "Error: The directory empty holds no file with a known subtitle extension.{$usage("convert")}"],
                          $this->runBinary(["convert", "empty", "--to", "vtt"]));
        foreach ([["--preset", "bbc"], ["--max-cpl", "20"]] as $rules) {
            $this->assertSame([2, "", "Error: --video-fps sets the frame rate of the netflix-en gap rule. Pass --preset netflix-en, or leave out " .
                                      "--video-fps.{$usage("validate")}"],
                              $this->runBinary(["validate", "trip.srt", ...$rules, "--video-fps", "25"]));
        }
        $this->assertSame(1, $this->runBinary(["validate", "trip.srt", "--preset", "netflix-en", "--video-fps", "25"])[0]);
        $this->assertSame(1, $this->runBinary(["validate", "trip.srt", "--preset", "bbc", "--fps", "25"])[0]);

        $this->assertSame([3, "", "missing.srt: The file does not exist.\n"], $this->runBinary(["convert", "missing.srt", "--to", "vtt"]));
        $this->assertSame([3, "", "Error: Cannot create the directory " . realpath($this->dir) . "/blocker/out.\n"],
                          $this->runBinary(["convert", "trip.srt", "--to", "vtt", "-o", "blocker/out/trip.vtt"]));
        $this->assertSame(3, $this->runBinary(["validate", "trip.srt", "missing.srt", "--max-cpl", "20", "--keep-going"])[0]);
        $this->assertSame(3, $this->runBinary(["diff", "trip.srt", "missing.srt"])[0]);
    }


    /**
     * @return array<string, array{list<string>, string, string}> arguments, file on standard input, start of standard error
     */
    public static function libraryMessagesInCliWords(): array
    {
        $tracks = "InvalidParserException (Error #102): The MKV or WebM file has 6 subtitle tracks. %s\n" .
                  "  3: S_TEXT/UTF8, de, \"Deutsch (Forced)\", forced\n";
        $format = "UnknownFormatException (Error #106): Format detection found no subtitle format. %s\n";
        $frames = "The frame rate is unknown. Pass --fps or --input-fps, or start the file with {1}{1}<fps>.\n";

        return [
            "track"            => [["convert", "movie.mkv", "--to", "srt", "-o", "-"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --track N with one of them:")],
            "track on stdin"   => [["convert", "-", "--from", "srt", "--to", "vtt", "-o", "-"], "movie.mkv",
                                   "stdin: InvalidParserException (Error #102): The input is an MKV or WebM file. Pass --track N.\n"],
            "track2"           => [["diff", "trip.srt", "movie.mkv"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --track2 N with one of them:")],
            "reference track"  => [["sync", "trip.srt", "--reference", "movie.mkv"], "",
                                   "Error: movie.mkv: " . sprintf($tracks, "Write one of them to a subtitle file with convert --track N first:")],
            "from"             => [["convert", "call.json", "--to", "srt", "-o", "-"], "",
                                   "call.json: " . sprintf($format, "Pass --from FORMAT. Chapters and cloud speech-to-text JSON always need it, " .
                                                                    "for example --from deepgram.")],
            "from2"            => [["diff", "trip.srt", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --from2 FORMAT. Chapters and cloud speech-to-text JSON " .
                                                                              "always need it, for example --from2 deepgram.")],
            "primary track"    => [["dual", "--primary", "movie.mkv", "--secondary", "trip.srt"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --primary-track N with one of them:")],
            "secondary track"  => [["dual", "--primary", "trip.srt", "--secondary", "movie.mkv"], "",
                                   "movie.mkv: " . sprintf($tracks, "Pass --secondary-track N with one of them:")],
            "primary from"     => [["dual", "--primary", "call.json", "--secondary", "trip.srt"], "",
                                   "call.json: " . sprintf($format, "Pass --primary-from FORMAT. Chapters and cloud speech-to-text JSON " .
                                                                    "always need it, for example --primary-from deepgram.")],
            "secondary from"   => [["dual", "--primary", "trip.srt", "--secondary", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --secondary-from FORMAT. Chapters and cloud speech-to-text " .
                                                                              "JSON always need it, for example --secondary-from deepgram.")],
            "reference format" => [["sync", "trip.srt", "--reference", "call.json"], "",
                                   "Error: call.json: " . sprintf($format, "Write it to a subtitle file with convert --from FORMAT first. " .
                                                                              "Chapters and cloud speech-to-text JSON always need --from, " .
                                                                              "for example --from deepgram.")],
            "microdvd output"  => [["convert", "trip.srt", "--to", "microdvd", "-o", "-"], "",
                                   "trip.srt: MicroDVD output needs the frame rate of the video. Pass --fps or --output-fps.\n"],
            "itt output"       => [["convert", "trip.srt", "--to", "itt", "-o", "-"], "",
                                   "trip.srt: iTT output needs the frame rate of the video. Pass --fps or --output-fps.\n"],
            "microdvd input"   => [["convert", "frames.sub", "--to", "srt", "-o", "-"], "", "frames.sub: ParsingException (Error #100): $frames"],
            "info microdvd"    => [["info", "frames.sub"], "", "frames.sub: ParsingException (Error #100): $frames"],
            "info from"        => [["info", "call.json"], "",
                                   "call.json: " . sprintf($format, "Pass --from FORMAT. Chapters and cloud speech-to-text JSON always need it, " .
                                                                    "for example --from deepgram.")],
            "csv frames"       => [["convert", "frames.csv", "--to", "srt", "-o", "-"], "",
                                   "frames.csv: ParsingException (Error #100): The time \"00:00:01:12\" counts frames. Pass --fps or --input-fps. (line 2)\n"],
            "scc line length"  => [["convert", "trip.srt", "--to", "scc", "-o", "-"], "",
                                   "trip.srt: Cue #1 at 2.5 s has a line with 57 characters, but SCC allows 32. Pass --structure-wrap --structure-max-cpl 32 --structure-max-lines 4.\n"],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("libraryMessagesInCliWords")]
    public function testLibraryMessagesNameCliOptions(array $arguments, string $stdin, string $stderr): void
    {
        copy(self::FILES . "mkv/text_tracks.mkv", "$this->dir/movie.mkv");
        copy(self::FILES . "deepgram/real/pool_utterances_diarize.json", "$this->dir/call.json");
        copy(self::FILES . "csv/own_frame_times.csv", "$this->dir/frames.csv");

        [$code, $stdout, $actual] = $this->runBinary($arguments, $stdin === "" ? "" : $this->file($stdin));

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith($stderr, $actual);
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function sideFiles(): array
    {
        $convert  = ["convert", "trip.srt", "shop.vtt", "--to", "srt"];
        $sync     = ["sync", "trip.srt", "shop.vtt"];
        $parsing  = "ParsingException (Error #100): ";

        return [
            "reference missing"           => [[...$sync, "--reference", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "reference broken"            => [[...$sync, "--reference", "bad.srt"], "bad.srt: $parsing"],
            "silence log missing"         => [[...$sync, "--silence-log", "missing.log", "--media-duration", "60"], "missing.log: The file does not exist.\n"],
            "silence log broken"          => [[...$sync, "--silence-log", "bad.log", "--media-duration", "60"], "bad.log: $parsing"],
            "word file missing"           => [[...$convert, "--mask-words", "missing.txt"], "missing.txt: The file does not exist.\n"],
            "replace list missing"        => [[...$convert, "--errors-fix", "--errors-replace-list", "missing.xml"], "missing.xml: The file does not exist.\n"],
            "replace list broken"         => [[...$convert, "--errors-fix", "--errors-replace-list", "bad.xml"], "bad.xml: $parsing"],
            "shot change file missing"    => [[...$convert, "--video-fps", "24", "--snap-shot-changes", "missing.txt"], "missing.txt: The file does not exist.\n"],
            "shot change file broken"     => [[...$convert, "--video-fps", "24", "--snap-shot-changes", "bad.txt"], "bad.txt: $parsing"],
            "glyph database missing"      => [[...$convert, "--ocr", "--ocr-database", "missing.nocr"], "missing.nocr: The file does not exist.\n"],
            "glyph database broken"       => [[...$convert, "--ocr", "--ocr-database", "bad.nocr"],
                                              "bad.nocr: Cannot read the glyph database - the data is not gzip-compressed!\n"],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("sideFiles")]
    public function testMissingOrBrokenSideFileStopsTheRunBeforeTheFirstInput(array $arguments, string $message): void
    {
        file_put_contents("$this->dir/bad.srt", "Not a subtitle.\n");
        file_put_contents("$this->dir/bad.log", "[silencedetect @ 0x1] channel: 0 | silence_start: 1.5\n");
        file_put_contents("$this->dir/bad.xml", "<OCRFixReplaceList><WholeWords>");
        file_put_contents("$this->dir/bad.txt", "Not a time.\n");
        file_put_contents("$this->dir/bad.nocr", "Not a glyph database.");

        [$code, $stdout, $stderr] = $this->runBinary([...$arguments, "--output-dir", "out", "--keep-going"]);

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith("Error: $message", $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"), $stderr);
        $this->assertDirectoryDoesNotExist("$this->dir/out");
    }


    public function testUsageErrorComesBeforeAMissingReference(): void
    {
        $this->assertSame([2, "", "Error: 2 input files need --output-dir DIR. One input file goes to standard output or to -o FILE.\n" .
                                  "Run \"subtitle-toolbox help sync\" for the usage.\n"],
                          $this->runBinary(["sync", "trip.srt", "shop.vtt", "--reference", "missing.srt"]));
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function secondFiles(): array
    {
        return [
            "diff missing" => [["diff", "trip.srt", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "diff broken"  => [["diff", "trip.srt", "bad.srt"], "bad.srt: ParsingException (Error #100): "],
            "dual missing" => [["dual", "--primary", "trip.srt", "--secondary", "missing.srt"], "missing.srt: The file does not exist.\n"],
            "dual broken"  => [["dual", "--primary", "trip.srt", "--secondary", "bad.srt"], "bad.srt: ParsingException (Error #100): "],
        ];
    }


    /**
     * @param list<string> $arguments
     */
    #[DataProvider("secondFiles")]
    public function testFailureOfTheSecondFileNamesOnlyThatFile(array $arguments, string $message): void
    {
        file_put_contents("$this->dir/bad.srt", "Not a subtitle.\n");

        [$code, $stdout, $stderr] = $this->runBinary($arguments);

        $this->assertSame([3, ""], [$code, $stdout]);
        $this->assertStringStartsWith($message, $stderr);
        $this->assertStringNotContainsString("trip.srt", $stderr);
    }


    public function testFormatsListsTheRegistry(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["formats"]);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\AName +Extensions +Read  Write\n/', $stdout);
        $this->assertMatchesRegularExpression('/^srt +\.srt +yes   yes$/m', $stdout);
        $this->assertMatchesRegularExpression('/^pgs +\.sup +yes   yes$/m', $stdout);
        $this->assertCount(count(Format::cases()) + 1, explode("\n", trim($stdout)));
        $this->assertSame("", $stderr);
    }
}
