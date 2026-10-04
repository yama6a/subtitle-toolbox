<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * Reads each input file, a glob or the files of a directory, and runs process() on it.
 */
abstract class FileCommand extends Command
{
    public const DASH = "-";

    protected ?Format $fromFormat = null;

    protected ?Format $secondFormat = null;

    protected ?float $inputFps = null;

    protected int $succeeded = 0;

    protected int $failed = 0;

    protected bool $fromContainer = false;

    protected ReadOptions $readOptions;

    /** @var list<ParseWarning> */
    protected array $parseWarnings = [];


    /**
     * @return list<Option>
     */
    abstract protected function commandOptions(): array;


    abstract protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void;


    public function options(): array
    {
        return [...$this->commandOptions(), ...$this->inputOptions()];
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("word-timestamps");
    }


    protected function fpsDescription(): string
    {
        return "Same as --input-fps.";
    }


    /**
     * @return list<Option>
     */
    protected function inputOptions(): array
    {
        return [
            Option::value("from", "FORMAT", "Input format. Default: detected from the content, else taken from the file extension. Chapters and cloud speech JSON need it."),
            Option::value("encoding", "NAME", "Encoding of the input, for example Windows-1252. Default: UTF-8. A BOM in the input overrides it."),
            Option::flag("lenient", "Skip or repair broken cues and print a warning for each. SCC, PGS, VobSub and chapter input ignore it."),
            Option::value("input-fps", "RATE", "Frame rate of a MicroDVD input without a {1}{1}<fps> first line, and of CSV or TSV times in hh:mm:ss:ff."),
            Option::value("fps", "RATE", $this->fpsDescription()),
            Option::flag("word-timestamps", "Keep the word times of speech-to-text JSON, YouTube timed text and podcast transcript input."),
            Option::value("track", "NUMBER", "Subtitle track of an MKV or WebM input. Needed when the file has several. \"info\" lists them."),
            Option::flag("keep-going", "Go on with the next file after a file fails. Default: stop at the first failure."),
        ];
    }


    /**
     * Returns --from2 and --track2, for a command that reads a second file.
     *
     * @return list<Option>
     */
    protected static function secondFileOptions(string $file): array
    {
        return [
            Option::value("from2", "FORMAT", "Format of the $file file. Default: detected from the content, else taken from the file extension."),
            Option::value("track2", "NUMBER", "Subtitle track of an MKV or WebM $file file. Needed when the file has several."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        $this->succeeded = 0;
        $this->failed    = 0;
        $this->inputFps  = self::rate($arguments, "input-fps");
        $arguments->positiveFloat("fps");
        $arguments->positiveInt("track");
        $arguments->positiveInt("track2");

        $from               = $arguments->value("from");
        $this->fromFormat   = $from === null ? null : self::readableFormat($from);
        $from2              = $arguments->value("from2");
        $this->secondFormat = $from2 === null ? null : self::readableFormat($from2);

        try {
            $this->readOptions = new ReadOptions(
                encoding: $arguments->value("encoding"),
                lenient: $arguments->has("lenient"),
                fps: $this->inputFps,
                wordTimestamps: $this->needsWordTimestamps($arguments),
            );
        } catch (SubtitleToolboxException $exception) {
            self::fail($exception->getMessage());
        }
    }


    /**
     * Returns the frame rate of the option $name, else of --fps, which sets all frame rates.
     */
    protected static function rate(Arguments $arguments, string $name): ?float
    {
        return $arguments->positiveFloat($name) ?? $arguments->positiveFloat("fps");
    }


    /**
     * @param list<string> $inputs
     */
    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
    }


    /**
     * @return list<string>
     */
    protected function inputArguments(Arguments $arguments): array
    {
        return $arguments->positionals;
    }


    protected function batchSummary(int $total, int $skipped): ?string
    {
        $summary = "$total files: $this->succeeded succeeded, $this->failed failed";

        return $summary . ($skipped > 0 ? ", $skipped skipped." : ".");
    }


    protected function report(Console $console, string $line): void
    {
        $console->out($line);
    }


    protected function finish(Console $console): void
    {
    }


    protected function exitCode(): int
    {
        return $this->failed > 0 ? Application::EXIT_FAILURE : Application::EXIT_OK;
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        $this->prepare($arguments);

        $inputs = $this->expandInputs($this->inputArguments($arguments));
        if ($inputs === []) {
            self::fail("Pass at least one input file, or - for standard input.");
        }
        $this->checkInputs($inputs, $arguments);

        foreach ($inputs as $input) {
            try {
                $read = $this->read($input, $arguments, $console);
                if ($read !== null) {
                    $this->process($input, $read[0], $read[1], $arguments, $console);
                }
                $this->succeeded++;
            } catch (\Throwable $exception) {
                $this->failed++;
                $console->err(self::label($input) . ": " . self::cliMessage(self::throwableMessage($exception), "--track", "--from") . "\n");
                if (!$arguments->has("keep-going")) {
                    break;
                }
            }
        }

        $this->finish($console);
        $skipped = count($inputs) - $this->succeeded - $this->failed;
        if ($skipped > 0) {
            $console->err("Stopped at the first failure. Pass --keep-going to process the other files.\n");
        }
        $summary = count($inputs) > 1 ? $this->batchSummary(count($inputs), $skipped) : null;
        if ($summary !== null) {
            $this->report($console, "$summary\n");
        }

        return $this->exitCode();
    }


    public static function label(string $input): string
    {
        return $input === self::DASH ? "stdin" : $input;
    }


    /**
     * Returns the format for a format name or a file extension such as "SRT" or ".ssa".
     */
    public static function findFormat(string $nameOrExtension): Format
    {
        $key = strtolower(ltrim($nameOrExtension, "."));

        return Format::tryFrom($key) ?? Format::fromPath("file.$key")
            ?? self::fail("Unknown format \"$nameOrExtension\". Run \"" . Application::NAME . " formats\" for the list.");
    }


    public static function readableFormat(string $nameOrExtension): Format
    {
        $format = self::findFormat($nameOrExtension);
        if (!$format->canRead()) {
            self::fail("The format $format->value can be written but not read.");
        }

        return $format;
    }


    /**
     * Returns the files for each argument: "-", a file, the subtitle files of a directory, or the matches of a glob.
     * A glob helps on shells that do not expand it, such as cmd.exe.
     *
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    protected function expandInputs(array $arguments): array
    {
        $inputs = [];
        foreach ($arguments as $argument) {
            if ($argument !== self::DASH && is_dir($argument)) {
                $files = $this->directoryFiles($argument);
                if ($files === []) {
                    self::fail("The directory $argument holds no file with a known subtitle extension.");
                }
                array_push($inputs, ...$files);
            } elseif ($argument === self::DASH || file_exists($argument) || strpbrk($argument, "*?[") === false) {
                $inputs[] = $argument;
            } else {
                $matches = array_values(array_filter(glob($argument) ?: [], "is_file"));
                array_push($inputs, ...($matches === [] ? [$argument] : $matches));
            }
        }

        return array_values(array_unique($inputs));
    }


    /**
     * @return list<string>
     */
    private function directoryFiles(string $directory): array
    {
        $directory = rtrim($directory, "/\\");
        $files     = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path   = "$directory/$name";
            $format = Format::fromPath($name);
            if (!is_file($path) || $format === null || !$format->canRead()) {
                continue;
            }
            // The .sub file of a VobSub pair is not MicroDVD. The parser reads it through its .idx file.
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === "sub"
                && glob($directory . "/" . pathinfo($name, PATHINFO_FILENAME) . ".[iI][dD][xX]") !== []) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }


    /**
     * Returns the subtitle and its format, or null when listTracks() handled an MKV or WebM input.
     *
     * @return array{Subtitle, Format}|null
     */
    protected function read(string $input, Arguments $arguments, Console $console): ?array
    {
        $this->parseWarnings = [];
        $track               = $arguments->positiveInt("track");
        if ($input !== self::DASH) {
            if (!is_file($input)) {
                self::fail("The file does not exist.");
            }
            $subtitle = $this->readPath($input, $input, $track, $console);
        } else {
            $subtitle = $this->readStdin($track, $console);
        }
        if ($subtitle === null) {
            return null;
        }
        // An MKV or WebM input has an extension of no subtitle format, so the output gets the extension of its format.
        $this->fromContainer = $track !== null || ($input !== self::DASH && Format::fromPath($input) === null);

        $this->parseWarnings = $subtitle->getParseWarnings();
        foreach ($this->parseWarnings as $warning) {
            $console->err(self::label($input) . ": line $warning->lineNumber: $warning->message ($warning->action)\n");
        }

        return [$subtitle, $subtitle->getFormat()];
    }


    /**
     * Rewords a library message that names a PHP method, class or option property, so that it names CLI options.
     * $track and $from are the options that pick the track and the format of the file, or null when it has none.
     */
    /**
     * Returns the message of a library exception, which names its class, or the class and message of another error.
     */
    public static function throwableMessage(\Throwable $throwable): string
    {
        return $throwable instanceof SubtitleToolboxException
            ? $throwable->getMessage()
            : $throwable::class . ": " . $throwable->getMessage();
    }


    public static function cliMessage(string $message, ?string $track, ?string $from): string
    {
        $pickTrack  = $track === null ? "Write one of them to a subtitle file with convert --track N first:" : "Pass $track N with one of them:";
        $pickFormat = $from === null
            ? "Write it to a subtitle file with convert --from FORMAT first. Chapters and cloud speech-to-text JSON always need --from, for example --from deepgram."
            : "Pass $from FORMAT. Chapters and cloud speech-to-text JSON always need it, for example $from deepgram.";
        $message    = preg_replace(
            '/^(\w+ \(Error #\d+\): )?.+ is an MKV or WebM file\. Call loadTrack\(\) with a track number\.$/s',
            '$1The input is an MKV or WebM file. ' . ($track === null ? "Write one track to a subtitle file with convert --track N first." : "Pass $track N."),
            $message
        ) ?? $message;

        return strtr($message, [
            "Call loadTrack() with one of them:"                                => $pickTrack,
            "Call load() with a format. Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram."       => $pickFormat,
            "Call fromString() with a format. Chapters and cloud speech-to-text JSON always need one, for example Format::Deepgram." => $pickFormat,
            "Pass MicroDvdOptions::frameRate."                                  => "Pass --fps or --output-fps.",
            "Pass IttOptions::frameRate."                                       => "Pass --fps or --output-fps.",
            "Set ReadOptions::\$fps or start the file with {1}{1}<fps>."       => "Pass --fps or --input-fps, or start the file with {1}{1}<fps>.",
            "Pass the frame rate in CsvColumns."                                => "Pass --fps or --input-fps.",
            "Call wrapLines(32, 4) first."                                      => "Pass --fix-wrap 32 --fix-max-lines 4.",
        ]);
    }


    /**
     * Reads a file other than the input, such as a reference, without --from and --track. Detects the format unless
     * $format or $track is given. $trackOption and $fromOption name the options that set $track and $format.
     */
    protected function loadOtherFile(string $path, ?Format $format = null, ?int $track = null, ?string $trackOption = null,
                                     ?string $fromOption = null): Subtitle
    {
        if (!is_file($path)) {
            self::fail("$path: The file does not exist.");
        }

        try {
            return match (true) {
                $track !== null  => Subtitle::loadTrack($path, $track, $this->readOptions),
                $format !== null => Subtitle::load($path, $format, $this->readOptions),
                default          => Subtitle::loadAutoDetectFormat($path, $this->readOptions),
            };
        } catch (SubtitleToolboxException $exception) {
            return self::fail("$path: " . self::cliMessage($exception->getMessage(), $trackOption, $fromOption));
        }
    }


    /**
     * Reads the second file of diff and dual with --from2 and --track2.
     */
    protected function loadSecondFile(string $path, Arguments $arguments): Subtitle
    {
        return $this->loadOtherFile($path, $this->secondFormat, $arguments->positiveInt("track2"), "--track2", "--from2");
    }


    /**
     * Handles the MKV or WebM file at $path without --track. $input is the argument that named it. Returns false to
     * read its only subtitle track.
     */
    protected function listTracks(string $path, string $input, Console $console): bool
    {
        return false;
    }


    /**
     * Returns null when listTracks() handled an MKV or WebM file.
     */
    private function readPath(string $path, string $input, ?int $track, Console $console): ?Subtitle
    {
        if ($track === null && $this->listTracks($path, $input, $console)) {
            return null;
        }

        return match (true) {
            $track !== null            => Subtitle::loadTrack($path, $track, $this->readOptions),
            $this->fromFormat !== null => Subtitle::load($path, $this->fromFormat, $this->readOptions),
            default                    => Subtitle::loadAutoDetectFormat($path, $this->readOptions),
        };
    }


    private function readStdin(?int $track, Console $console): ?Subtitle
    {
        $content = $console->readStdin();
        if ($track !== null || str_starts_with($content, MatroskaReader::EBML_MAGIC)) {
            // The track list and loadTrack() need a file.
            $path = tempnam(sys_get_temp_dir(), Application::NAME . "-");
            try {
                file_put_contents($path, $content);

                return $this->readPath($path, self::DASH, $track, $console);
            } finally {
                @unlink($path);
            }
        }
        if ($this->fromFormat === Format::VobSub) {
            self::fail("VobSub needs the path of the .idx file. Standard input does not work.");
        }

        return $this->fromFormat === null
            ? Subtitle::fromStringAutoDetectFormat($content, $this->readOptions)
            : Subtitle::fromString($content, $this->fromFormat, $this->readOptions);
    }
}
