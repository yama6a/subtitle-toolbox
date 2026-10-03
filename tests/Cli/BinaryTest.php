<?php

namespace SubtitleToolbox\Cli;

use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Diff\SubtitleDiff;
use SubtitleToolbox\Diff\SubtitleDiffOptions;
use SubtitleToolbox\DualSubtitle;
use SubtitleToolbox\DualSubtitleOptions;
use SubtitleToolbox\Format;
use SubtitleToolbox\Hls\HlsSegmentOptions;
use SubtitleToolbox\Hls\HlsWebVttSegmenter;
use SubtitleToolbox\MergeShortCuesOptions;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Parsers\WhisperJsonParser;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\ResegmentOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Sync\ReferenceSync;
use SubtitleToolbox\Sync\SpeechReference;
use SubtitleToolbox\Timing\ShotChangeOptions;
use SubtitleToolbox\Timing\ShotChanges;
use SubtitleToolbox\Timing\ShotChangeTiming;

/**
 * Runs bin/subtitle-toolbox as a separate process in a temporary directory with copies of the fixtures.
 */
class BinaryTest extends TestCase
{
    private const BIN = __DIR__ . "/../../bin/subtitle-toolbox";

    private const FIXTURES = __DIR__ . "/../files/cli/";

    private const FILES = __DIR__ . "/../files/";

    private const BOM = "\xEF\xBB\xBF";

    private const COMMANDS = ["convert", "shift", "scale", "fps", "sync-fps", "fix", "strip-sdh", "info", "validate", "sync", "diff", "dual", "snap", "hls", "formats"];

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


    private function tripAs(Format $format): string
    {
        return Subtitle::fromString(file_get_contents(self::FIXTURES . "trip.srt"), Format::SubRip)->toString($format);
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
        foreach (["convert", "shift", "scale", "fps", "fix", "strip-sdh", "info", "validate", "sync", "diff", "dual", "snap", "hls", "formats", "help"] as $command) {
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
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("trip.vtt"));
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
        $this->assertSame(Subtitle::fromStringAutoDetectFormat($this->file("lecture.json"))->toString(Format::Json), $this->file("out.json"));
    }


    public function testConvertNeverOverwritesWithoutForce(): void
    {
        file_put_contents("$this->dir/trip.vtt", "old");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "trip.srt", "trip.vtt"]);
        $this->assertSame([1, "", "trip.srt: trip.vtt exists. Pass --force to overwrite it.\n"], [$code, $stdout, $stderr]);
        $this->assertSame("old", $this->file("trip.vtt"));

        $this->assertSame(0, $this->runBinary(["convert", "trip.srt", "trip.vtt", "--force"])[0]);
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("trip.vtt"));

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
        $this->assertSame($this->tripAs(Format::WebVtt), $this->file("out/trip.vtt"));
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
        $this->assertSame($this->tripAs(Format::SubRip), $this->file("out/trip.srt"));
    }


    public function testDashReadsStandardInputAndWritesStandardOutput(): void
    {
        [$code, $stdout, $stderr] = $this->runBinary(["convert", "-", "--to", "vtt"], file_get_contents(self::FIXTURES . "trip.srt"));

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], [$code, $stdout, $stderr]);
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "trip.srt", "-o", "-", "--to", "vtt"]));
        $this->assertSame([0, $stdout, ""], $this->runBinary(["convert", "-", "--from", "srt", "--to", "vtt"], $this->file("trip.srt")));
    }


    public function testTheContentSetsTheInputFormatBeforeTheExtension(): void
    {
        rename("$this->dir/trip.srt", "$this->dir/trip.txt");

        $this->assertSame([0, $this->tripAs(Format::WebVtt), ""], $this->runBinary(["convert", "trip.txt", "--to", "vtt", "-o", "-"]));
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
        $expected = Subtitle::fromStringAutoDetectFormat($this->file("signs.itt"))->forcedOnly()->toString(Format::SubRip);

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


    public function testReplaceAndCase(): void
    {
        copy(self::FILES . "transforms/own_cea608_caps.vtt", "$this->dir/caps.vtt");
        copy(self::FILES . "transforms/own_multilingual_caps.srt", "$this->dir/multi.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "transforms/own_cea608_caps_sentence.vtt"), ""],
                          $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--case", "sentence"]));
        $this->assertSame(
            [0, file_get_contents(self::FILES . "transforms/own_cea608_caps_cleaned.vtt"), ""],
            $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", "--regex", "--replace", '/\[[^\]]*\]/=',
                              "--replace", '/\.{4,}/=...', "--strip-tags"])
        );

        $expected = Subtitle::fromStringAutoDetectFormat($this->file("multi.srt"))->replaceText("uhr", "Uhr", false, false)->changeCase("lower", "tr");
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "convert", "multi.srt", "--to", "srt", "-o", "-", "--replace", "uhr=Uhr", "--ignore-case", "--case", "lower", "--case-language", "tr",
        ]));
        $this->assertStringContainsString("<font color=\"#ffff00\">istasyon kap\u{131}s\u{131} \u{131}\u{15f}\u{131}kl\u{131}.</font>",
                                          $this->runBinary(["convert", "multi.srt", "--to", "srt", "-o", "-", "--case", "lower", "--case-language", "tr"])[1]);

        foreach ([["--replace", "colour"], ["--replace", "=x"], ["--regex", "--replace", "/(/=x"], ["--regex"], ["--ignore-case"],
                  ["--case", "title"], ["--case-language", "tr"]] as $options) {
            $this->assertSame(2, $this->runBinary(["convert", "caps.vtt", "--to", "vtt", "-o", "-", ...$options])[0], implode(" ", $options));
        }
    }


    public function testMaskWords(): void
    {
        $files = __DIR__ . "/../files/profanity/";
        copy($files . "keys.srt", "$this->dir/keys.srt");
        copy($files . "words.txt", "$this->dir/words.txt");
        $masked = function (string $mask): string {
            $subtitle = Subtitle::fromStringAutoDetectFormat($this->file("keys.srt"));
            ProfanityFilter::apply($subtitle, new ProfanityOptions(mask: $mask, wordFile: "$this->dir/words.txt"));

            return $subtitle->toString(Format::SubRip);
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


    public function testMuteRanges(): void
    {
        copy(self::FILES . "profanity/radio.vtt", "$this->dir/radio.vtt");
        copy(self::FILES . "profanity/words.txt", "$this->dir/words.txt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($this->file("radio.vtt"));
        $ranges   = ProfanityFilter::apply($subtitle, new ProfanityOptions(mask: ProfanityOptions::MASK_NONE, padding: 0.1,
                                                                           wordFile: "$this->dir/words.txt"));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "radio.vtt", "out.vtt", "--mask-words", "words.txt", "--mask", "none",
                                                      "--mute-edl", "radio.edl", "--mute-filter", "radio.af", "--mute-padding", "0.1"]);
        $this->assertSame([0, "radio.vtt -> out.vtt\nradio.vtt -> radio.edl\nradio.vtt -> radio.af\n", ""], [$code, $stdout, $stderr]);
        $this->assertSame($subtitle->toString(Format::WebVtt), $this->file("out.vtt"));
        $this->assertSame(MuteRange::toEdl($ranges), $this->file("radio.edl"));
        $this->assertSame("1.500 2.100 1\n6.200 6.800 1\n7.900 9.100 1\n", $this->file("radio.edl"));
        $this->assertSame(MuteRange::toFfmpegVolumeFilter($ranges) . "\n", $this->file("radio.af"));

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "radio.vtt", "--to", "srt", "-o", "-", "--mask-words", "words.txt",
                                                      "--mute-edl", "-"]);
        $this->assertSame([2, ""], [$code, $stdout]);
        $this->assertSame([2, "", "Error: radio.edl exists. Pass --force to overwrite it.\nRun \"subtitle-toolbox help convert\" for the usage.\n"],
                          $this->runBinary(["convert", "radio.vtt", "--to", "srt", "--mask-words", "words.txt", "--mute-edl", "radio.edl"]));
        $this->assertSame(2, $this->runBinary(["convert", "radio.vtt", "--to", "srt", "--mute-edl", "new.edl"])[0]);
        $this->assertSame(2, $this->runBinary(["convert", "radio.vtt", "trip.srt", "--to", "vtt", "--output-dir", "out",
                                               "--mask-words", "words.txt", "--mute-edl", "new.edl"])[0]);
        $this->assertFileDoesNotExist("$this->dir/new.edl");
    }


    public function testKaraoke(): void
    {
        copy(self::FILES . "whisper/real/openai_whisper_word_timestamps.json", "$this->dir/song.json");
        copy(self::FILES . "lrc/real/handwritten-enhanced.lrc", "$this->dir/song.lrc");

        $this->assertSame([0, "song.json -> word.srt\n", ""], $this->runBinary(["convert", "song.json", "word.srt", "--karaoke"]));
        $this->assertFileEquals(self::FILES . "karaoke/whisper_word.srt", "$this->dir/word.srt");
        $this->assertSame(
            [0, file_get_contents(self::FILES . "karaoke/whisper_one_word.vtt"), ""],
            $this->runBinary(["convert", "song.json", "--to", "vtt", "-o", "-", "--karaoke", "--karaoke-style", "b", "--karaoke-words", "1"])
        );
        $this->assertSame(
            [0, file_get_contents(self::FILES . "karaoke/lrc_cumulative.srt"), ""],
            $this->runBinary(["convert", "song.lrc", "--to", "srt", "-o", "-", "--karaoke", "--karaoke-mode", "cumulative",
                              "--karaoke-style", 'font color="#ffff00"'])
        );
        $this->assertSame([0, file_get_contents(self::FILES . "karaoke/whisper_kf.ass"), ""],
                          $this->runBinary(["convert", "song.json", "--to", "ass", "-o", "-", "--karaoke-tag", "kf"]));

        $this->assertSame([1, "", "song.json: --karaoke-tag needs ASS output.\n"],
                          $this->runBinary(["convert", "song.json", "--to", "srt", "-o", "-", "--karaoke-tag", "kf"]));
        foreach ([["--karaoke-tag", "x"], ["--karaoke", "--karaoke-tag", "k"], ["--karaoke-words", "2"], ["--karaoke", "--karaoke-style", "em"],
                  ["--karaoke", "--karaoke-mode", "all"], ["--karaoke", "--karaoke-words", "0"]] as $options) {
            $this->assertSame(2, $this->runBinary(["convert", "song.json", "--to", "srt", "-o", "-", ...$options])[0], implode(" ", $options));
        }
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
        $expected = Subtitle::fromString($this->file("trip.srt"), Format::SubRip)->shift(-1.5)->toString(Format::SubRip);

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
        $expected = Subtitle::fromStringAutoDetectFormat($this->file("shop.vtt"))->convertFrameRate(25, 23.976)->toString(Format::WebVtt);

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
        $narrow = Subtitle::fromStringAutoDetectFormat($this->file("speech.srt"))
            ->mergeShortCues(new MergeShortCuesOptions(maxCharactersPerLine: 20, maxLines: 3))
            ->toString(Format::SubRip);

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
            Subtitle::fromStringAutoDetectFormat($this->file("whisper.json"))->splitLongCues($options)->toString(Format::WebVtt);

        [$code, $stdout, $stderr] = $this->runBinary(["fix", "whisper.json", "--split-long", "--to", "vtt"]);

        $this->assertSame([0, $split(new ResegmentOptions()), ""], [$code, $stdout, $stderr]);
        $this->assertGreaterThan(count(Subtitle::fromStringAutoDetectFormat($this->file("whisper.json"))->getCues()), substr_count($stdout, " --> "));
        $this->assertSame(
            [0, $split(new ResegmentOptions(maxCharactersPerLine: 30, maxLines: 1)), ""],
            $this->runBinary(["fix", "whisper.json", "--split-long", "--max-cpl", "30", "--max-lines", "1", "--to", "vtt"])
        );
    }


    public function testFixCommonErrors(): void
    {
        copy(self::FILES . "fixing/web-errors.srt", "$this->dir/web.srt");
        copy(self::FILES . "fixing/text-pal.ocr.srt", "$this->dir/pal.srt");
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


    public function testWordTimestampsAndResegment(): void
    {
        copy(self::FILES . "resegmenting/own_whisper_long_segments.json", "$this->dir/lecture.json");
        $withWords = fn (): Subtitle => (new WhisperJsonParser([WhisperJsonParser::OPTION_WORD_TIMESTAMPS => true]))->parse($this->file("lecture.json"));

        [$code, $stdout, $stderr] = $this->runBinary(["fix", "lecture.json", "--resegment", "-o", "lecture.srt"]);
        $this->assertSame([0, "lecture.json -> lecture.srt\n", ""], [$code, $stdout, $stderr]);
        $this->assertFileEquals(self::FILES . "resegmenting/own_whisper_long_segments_resegmented.srt", "$this->dir/lecture.srt");

        $options = new ResegmentOptions(maxCharactersPerLine: 30, maxLines: 1, maxWordGap: 0.3);
        $this->assertSame(
            [0, $withWords()->resegmentByWords($options)->toString(Format::SubRip), ""],
            $this->runBinary(["fix", "lecture.json", "--resegment", "--max-cpl", "30", "--max-lines", "1", "--max-word-gap", "0.3", "--to", "srt"])
        );

        $this->assertSame([0, $withWords()->toString(Format::WebVtt), ""],
                          $this->runBinary(["convert", "lecture.json", "--to", "vtt", "-o", "-", "--word-timestamps"]));
        $this->assertStringNotContainsString("<00:", $this->runBinary(["convert", "lecture.json", "--to", "vtt", "-o", "-"])[1]);
        $this->assertSame(2, $this->runBinary(["fix", "lecture.json", "--overlaps", "--max-word-gap", "1"])[0]);
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


    public function testInfoListsTheParseWarnings(): void
    {
        $this->assertSame([], json_decode($this->runBinary(["info", "trip.srt", "--json"])[1], true)["warnings"]);

        [$code, $stdout, $stderr] = $this->runBinary(["info", "broken.srt", "--json", "--lenient"]);
        $this->assertSame(0, $code);
        $this->assertSame([[
            "lineNumber" => 5,
            "blockIndex" => 1,
            "message"    => "Block #1 doesn't seem to have its timestamps on its second line!",
            "action"     => "skipped",
        ]], json_decode($stdout, true)["warnings"]);
        $this->assertSame("broken.srt: line 5: Block #1 doesn't seem to have its timestamps on its second line! (skipped)\n", $stderr);

        $this->assertMatchesRegularExpression('/^  Warnings: +1$/m', $this->runBinary(["info", "broken.srt", "--lenient"])[1]);
        $this->assertDoesNotMatchRegularExpression('/Warnings/', $this->runBinary(["info", "trip.srt"])[1]);
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
        $this->assertSame($mkv->extract(3)->toString(Format::SubRip), $this->file("movie.srt"));

        [$code, $stdout] = $this->runBinary(["convert", "-", "--to", "vtt", "--track", "5"], $this->file("movie.mkv"));
        $this->assertSame([0, $mkv->extract(5)->toString(Format::WebVtt)], [$code, $stdout]);

        $this->assertSame([0, "one.webm -> one.vtt\n", ""], $this->runBinary(["convert", "one.webm", "one.vtt"]));
        $this->assertSame(MatroskaReader::open(self::FILES . "mkv/seek_head.mkv")->extract(2)->toString(Format::WebVtt),
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

        $this->assertSame([0, "text.sup -> text.srt\n", "text.sup: OCR 12/12\n"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr", "--ocr-engine", "glyph"]));
        $this->assertFileEquals(__DIR__ . "/../files/pgs/text_1080p.ocr.srt", "$this->dir/text.srt");

        [$code, $stdout, $stderr] = $this->runBinary(["convert", "text.idx", "--to", "srt", "--output", "-", "--ocr", "--ocr-engine", "glyph"]);
        $this->assertSame([0, "text.idx: OCR 6/6\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(__DIR__ . "/../files/vobsub/text-pal.ocr.srt", $stdout);
    }


    public function testOcrDatabaseReplacesTheLatinDatabase(): void
    {
        copy(__DIR__ . "/../files/pgs/text_1080p.sup", "$this->dir/text.sup");
        (new GlyphDatabase())->save("$this->dir/empty.nocr");

        [$code, $stdout] = $this->runBinary(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-database", "empty.nocr"]);

        $this->assertSame(0, $code);
        $subtitle = Subtitle::fromStringAutoDetectFormat($stdout);
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


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runWithPath(string $path, array $arguments): array
    {
        $original = getenv("PATH");
        putenv("PATH=$path");
        try {
            return $this->runBinary($arguments);
        } finally {
            putenv("PATH=$original");
        }
    }


    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    private function runWithFakeTesseract(array $arguments): array
    {
        return $this->runWithPath(realpath(self::FILES . "ocr/fake-tesseract") . PATH_SEPARATOR . getenv("PATH"), $arguments);
    }


    public function testOcrPrefersTesseractAndPassesTheLanguage(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");

        [$code, $stdout, $stderr] = $this->runWithFakeTesseract(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr",
                                                                 "--ocr-language", "deu+eng"]);

        $this->assertSame([0, "text.sup: OCR 12/12\n"], [$code, $stderr]);
        $cues = Subtitle::fromStringAutoDetectFormat($stdout)->getCues();
        $this->assertCount(12, $cues);
        $this->assertSame("deu+eng psm6", $cues[0]->getLines()[0]);
    }


    public function testGlyphEngineIgnoresTheOcrLanguageWithOneWarning(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/again.sup");

        $this->assertSame([0, "text.sup -> text.srt\nagain.sup -> again.srt\n2 files: 2 succeeded, 0 failed.\n",
                           "Warning: the glyph engine ignores --ocr-language.\ntext.sup: OCR 12/12\nagain.sup: OCR 12/12\n"],
                          $this->runWithFakeTesseract(["convert", "text.sup", "again.sup", "--to", "srt", "--ocr",
                                                       "--ocr-engine", "glyph", "--ocr-language", "deu"]));
        $this->assertFileEquals(self::FILES . "pgs/text_1080p.ocr.srt", "$this->dir/text.srt");
    }


    public function testOcrEngineErrors(): void
    {
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        $usage = "Run \"subtitle-toolbox help convert\" for the usage.\n";

        $this->assertSame([2, "", "Error: Cannot choose the OCR engine \"easyocr\" - the engines are: tesseract, glyph!\n$usage"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr", "--ocr-engine", "easyocr"]));
        $this->assertSame([2, "", "Error: --ocr-database works only with the glyph engine.\n$usage"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr", "--ocr-engine", "tesseract",
                                            "--ocr-database", "my.nocr"]));
        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-engine.\n$usage"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr-engine", "glyph"]));
        $this->assertSame([2, "", "Error: Pass --ocr with --ocr-language.\n$usage"],
                          $this->runBinary(["convert", "text.sup", "text.srt", "--ocr-language", "deu"]));
        $this->assertSame([2, "", "Error: Cannot run OCR with Tesseract - the program \"tesseract\" is missing! " .
                                  TesseractOcrEngine::INSTALL_HINT . "\n$usage"],
                          $this->runWithPath($this->dir, ["convert", "text.sup", "text.srt", "--ocr", "--ocr-engine", "tesseract"]));
        $this->assertSame([1, "", "text.sup: Cannot run OCR with Tesseract in the language \"fra\" - the language data of " .
                                  "fra is missing! Install it, for example with apt install tesseract-ocr-fra. The " .
                                  "installed languages are: deu, eng, osd.\n"],
                          $this->runWithFakeTesseract(["convert", "text.sup", "text.srt", "--ocr", "--ocr-language", "fra"]));
        $this->assertFileDoesNotExist("$this->dir/text.srt");
    }


    #[Group("tesseract")]
    public function testOcrWithTesseractReadsLatinAndCyrillicText(): void
    {
        if (!TesseractOcrEngine::isInstalled()) {
            $this->markTestSkipped("Tesseract is not installed.");
        }
        copy(self::FILES . "pgs/text_1080p.sup", "$this->dir/text.sup");
        copy(self::FILES . "pgs/text_cyrillic_1080p.sup", "$this->dir/cyrillic.sup");

        [$code, $stdout] = $this->runBinary(["convert", "text.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-engine", "tesseract"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nThe train to Bergen leaves at 7:45.\n", $stdout);

        [$code, $stdout] = $this->runBinary(["convert", "cyrillic.sup", "--to", "srt", "-o", "-", "--ocr", "--ocr-language", "rus"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("\nПоезд в Берген уходит в 7:45.\n", $stdout);
    }


    public function testOcrLeavesFilesWithoutImageCuesAsTheyAre(): void
    {
        $this->assertSame([0, "trip.srt -> trip.vtt\n", ""], $this->runBinary(["convert", "trip.srt", "--to", "vtt", "--ocr"]));
        $this->assertSame(Subtitle::fromStringAutoDetectFormat($this->file("trip.srt"))->toString(Format::WebVtt), $this->file("trip.vtt"));
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


    public function testSyncToAReferenceSubtitle(): void
    {
        foreach (["own_target_de_25fps.srt" => "de.srt", "own_reference_en.srt" => "en.srt", "own_reference_en_tv_break.srt" => "tv.srt"] as $from => $to) {
            copy(self::FILES . "sync/$from", "$this->dir/$to");
        }

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "en.srt"]);
        $this->assertSame([0, "de.srt: scale 1.04271, offset -2.3 s, score 0.89\n"], [$code, $stderr]);
        $this->assertStringEqualsFile(self::FILES . "sync/own_target_de_synced.srt", $stdout);

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "tv.srt", "--min-offset", "-180", "--max-offset", "180",
                                                      "--max-splits", "2", "-o", "synced.srt"]);
        $this->assertSame([0, "de.srt -> synced.srt\n"], [$code, $stdout]);
        $this->assertSame("de.srt: scale 1.04271, offset -2.31 s, score 0.89\nde.srt: from 0 s: offset -2.31 s\n" .
                          "de.srt: from 414.32 s: offset 147.7 s\n", $stderr);
        $this->assertFileEquals(self::FILES . "sync/own_target_de_split_synced.srt", "$this->dir/synced.srt");

        [$code, , $stderr] = $this->runBinary(["sync", "de.srt", "--reference", "trip.srt", "--no-scale"]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("de.srt: scale 1, ", $stderr);
        $this->assertStringEndsWith("de.srt: the score is below 0.5, so the files likely do not match.\n", $stderr);

        $this->assertSame([1, "", "de.srt: missing.srt: The file does not exist.\n"], $this->runBinary(["sync", "de.srt", "--reference", "missing.srt"]));
        $this->assertSame(2, $this->runBinary(["sync", "de.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["sync", "de.srt", "--reference", "en.srt", "--min-offset", "10", "--max-offset", "-10"])[0]);
        $this->assertSame(2, $this->runBinary(["sync", "de.srt", "--reference", "en.srt", "--max-splits", "two"])[0]);
    }


    public function testSyncToTheSpeech(): void
    {
        copy(self::FILES . "sync/own_target_de_25fps.srt", "$this->dir/de.srt");
        copy(self::FILES . "sync/own_ffmpeg_silencedetect.log", "$this->dir/silence.log");
        $expected = Subtitle::fromStringAutoDetectFormat($this->file("de.srt"));
        ReferenceSync::sync($expected, SpeechReference::fromFfmpegSilencedetect($this->file("silence.log"), 840))->apply($expected);

        [$code, $stdout, $stderr] = $this->runBinary(["sync", "de.srt", "--silence-log", "silence.log", "--media-duration", "840"]);
        $this->assertSame([0, $expected->toString(Format::SubRip), "de.srt: scale 1.04271, offset -2.3 s, score 0.78\n"],
                          [$code, $stdout, $stderr]);

        foreach ([["--silence-log", "silence.log"], ["--media-duration", "840"], ["--reference", "trip.srt", "--silence-log", "silence.log",
                  "--media-duration", "840"]] as $options) {
            $this->assertSame(2, $this->runBinary(["sync", "de.srt", ...$options])[0], implode(" ", $options));
        }
        file_put_contents("$this->dir/mono.log", "[silencedetect @ 0x1] channel: 0 | silence_start: 1.5\n");
        $this->assertSame(1, $this->runBinary(["sync", "de.srt", "--silence-log", "mono.log", "--media-duration", "840"])[0]);
    }


    public function testSnapToShotChanges(): void
    {
        foreach (["own_garden_24fps.srt" => "garden.srt", "own_ffmpeg_showinfo.log" => "scenes.log", "own_scenes.txt" => "scenes.txt"] as $from => $to) {
            copy(self::FILES . "shot-changes/$from", "$this->dir/$to");
        }
        $timed = file_get_contents(self::FILES . "shot-changes/own_garden_24fps_timed.srt");

        $this->assertSame([0, $timed, ""], $this->runBinary(["snap", "garden.srt", "--fps", "24", "--shot-changes", "scenes.log"]));
        $this->assertSame([0, $timed, ""], $this->runBinary(["snap", "garden.srt", "--fps", "24", "--shot-changes", "scenes.txt"]));

        $options  = new ShotChangeOptions(frameRate: 24, snapWindow: 6, minGapFrames: 3, chain: false, minDuration: 12);
        $expected = ShotChangeTiming::apply(Subtitle::fromStringAutoDetectFormat($this->file("garden.srt")), ShotChanges::fromText($this->file("scenes.txt")), $options);
        $this->assertSame([0, $expected->toString(Format::SubRip), ""], $this->runBinary([
            "snap", "garden.srt", "--fps", "24", "--shot-changes", "scenes.txt", "--snap-window", "6", "--min-gap-frames", "3",
            "--no-chain", "--min-duration-frames", "12",
        ]));

        $chained = ShotChangeTiming::chainGaps(Subtitle::fromStringAutoDetectFormat($this->file("garden.srt")), new ShotChangeOptions(24));
        $this->assertSame([0, $chained->toString(Format::SubRip), ""], $this->runBinary(["snap", "garden.srt", "--fps", "24"]));

        foreach ([[], ["--fps", "24", "--no-chain"], ["--fps", "24", "--snap-window", "-1"], ["--fps", "24", "--shot-changes", "missing.txt"],
                  ["--fps", "24", "--shot-changes", "garden.srt"]] as $options) {
            $this->assertSame(2, $this->runBinary(["snap", "garden.srt", ...$options])[0], implode(" ", $options));
        }
    }


    public function testDiff(): void
    {
        copy(self::FILES . "diff/own_original.srt", "$this->dir/v1.srt");
        copy(self::FILES . "diff/own_edited.srt", "$this->dir/v2.srt");
        file_put_contents("$this->dir/v1.vtt", Subtitle::fromStringAutoDetectFormat($this->file("v1.srt"))->toString(Format::WebVtt));

        $this->assertSame([1, file_get_contents(self::FILES . "diff/own_report.txt"), ""], $this->runBinary(["diff", "v1.srt", "v2.srt"]));
        $this->assertSame([0, "", ""], $this->runBinary(["diff", "v1.srt", "v1.vtt"]));

        $options  = new SubtitleDiffOptions(timeTolerance: 0.5, ignoreFormatting: true, textOnly: true);
        $expected = SubtitleDiff::compare(Subtitle::fromStringAutoDetectFormat($this->file("v1.srt")), Subtitle::fromStringAutoDetectFormat($this->file("v2.srt")), $options);
        [$code, $stdout, $stderr] = $this->runBinary(["diff", "v1.srt", "v2.srt", "--json", "--time-tolerance", "0.5", "--ignore-formatting", "--text-only"]);
        $this->assertSame([1, ""], [$code, $stderr]);
        $json = json_decode($stdout, true);
        $this->assertSame(["v1.srt", "v2.srt", false], [$json["old"], $json["new"], $json["equal"]]);
        $this->assertSame(array_map(fn ($difference): string => $difference->getKind(), $expected), array_column($json["differences"], "kind"));
        $old = $expected[0]->getOldCue();
        $this->assertEquals(["start" => $old->getStart(), "end" => $old->getEnd(), "lines" => $old->getLines(), "forced" => false],
                            $json["differences"][0]["old"]);
        $this->assertSame($expected[0]->getOldIndex(), $json["differences"][0]["oldIndex"]);

        $this->assertSame(2, $this->runBinary(["diff", "v1.srt"])[0]);
        $this->assertSame(2, $this->runBinary(["diff", "v1.srt", "v2.srt", "--time-tolerance", "-1"])[0]);
        $this->assertSame([1, "", "v1.srt: missing.srt: The file does not exist.\n"], $this->runBinary(["diff", "v1.srt", "missing.srt"]));
    }


    public function testDual(): void
    {
        copy(self::FILES . "dual/station_en.srt", "$this->dir/en.srt");
        copy(self::FILES . "dual/station_de.srt", "$this->dir/de.srt");

        $this->assertSame([0, file_get_contents(self::FILES . "dual/station_stack.srt"), ""],
                          $this->runBinary(["dual", "en.srt", "de.srt", "--secondary-style", "i"]));
        $this->assertSame([0, "en.srt -> both.vtt\n", ""],
                          $this->runBinary(["dual", "en.srt", "de.srt", "--secondary-style", "i", "-o", "both.vtt"]));
        $this->assertFileEquals(self::FILES . "dual/station_stack.vtt", "$this->dir/both.vtt");
        $this->assertSame(
            [0, file_get_contents(self::FILES . "dual/station_top_bottom.ass"), ""],
            $this->runBinary(["dual", "en.srt", "de.srt", "--mode", "top-bottom", "--secondary-style", 'font color="#ffff00"', "--to", "ass"])
        );

        $merged = DualSubtitle::merge(Subtitle::fromStringAutoDetectFormat($this->file("en.srt")), Subtitle::fromStringAutoDetectFormat($this->file("de.srt")),
                                      new DualSubtitleOptions(mode: DualSubtitleOptions::MODE_TOP_BOTTOM, snapTolerance: 0.5, secondaryAlignment: 7));
        $this->assertSame([0, $merged->toString(Format::SubRip), ""], $this->runBinary([
            "dual", "en.srt", "de.srt", "--mode", "top-bottom", "--snap-tolerance", "0.5", "--secondary-alignment", "7",
        ]));

        foreach ([[], ["--mode", "side"], ["--secondary-alignment", "0"], ["--secondary-style", "em"], ["--snap-tolerance", "-1"]] as $options) {
            $this->assertSame(2, $this->runBinary(["dual", "en.srt", ...($options === [] ? [] : ["de.srt"]), ...$options])[0], implode(" ", $options));
        }
    }


    public function testHlsWritesTheSegmentsAndThePlaylist(): void
    {
        copy(self::FILES . "hls/node-webvtt-subs1.vtt", "$this->dir/talk.vtt");
        $expected = HlsWebVttSegmenter::segment(
            Subtitle::fromStringAutoDetectFormat($this->file("talk.vtt")),
            new HlsSegmentOptions(segmentDuration: 10, mpegts: 126000, fileNamePattern: "part%03d.vtt", mediaDuration: 150)
        );

        $this->assertSame([0, "talk.vtt -> out/index.m3u8, 15 segments\n", ""], $this->runBinary([
            "hls", "talk.vtt", "--output-dir", "out", "--segment", "10", "--mpegts", "126000", "--pattern", "part%03d.vtt",
            "--media-duration", "150", "--playlist", "index.m3u8",
        ]));
        $this->assertSame($expected->getPlaylist(), $this->file("out/index.m3u8"));
        foreach ($expected->getSegments() as $name => $content) {
            $this->assertSame($content, $this->file("out/$name"));
        }
        $this->assertCount(16, glob("$this->dir/out/*"));

        $this->assertSame([1, "", "talk.vtt: out/part000.vtt exists. Pass --force to overwrite it.\n"],
                          $this->runBinary(["hls", "talk.vtt", "--output-dir", "out", "--pattern", "part%03d.vtt"]));
        $this->assertSame(0, $this->runBinary(["hls", "talk.vtt", "--output-dir", "out", "--pattern", "part%03d.vtt", "--force"])[0]);
        $this->assertSame(2, $this->runBinary(["hls", "talk.vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["hls", "talk.vtt", "--output-dir", "out", "--pattern", "part.vtt"])[0]);
        $this->assertSame(2, $this->runBinary(["hls", "talk.vtt", "trip.srt", "--output-dir", "out"])[0]);
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
