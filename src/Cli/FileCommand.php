<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

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

    protected ?float $fps = null;

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


    /**
     * Returns false for a command that uses --from and --to for something other than formats.
     */
    protected function hasFormatOptions(): bool
    {
        return true;
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("word-timestamps");
    }


    protected function fpsDescription(): string
    {
        return "Frame rate of the video. MicroDVD files without a {1}{1}<fps> first line need it.";
    }


    /**
     * @return list<Option>
     */
    protected function inputOptions(): array
    {
        $options = [];
        if ($this->hasFormatOptions()) {
            $options[] = Option::value("from", "FORMAT", "Input format. Default: detected from the content, else taken from the file extension. Chapters and cloud speech JSON need it.");
        }

        return [
            ...$options,
            Option::value("encoding", "NAME", "Encoding of the input, for example Windows-1252. Default: UTF-8. A BOM in the input overrides it."),
            Option::flag("lenient", "Skip or repair broken cues and print a warning for each. SCC, PGS, VobSub and chapter input ignore it."),
            Option::value("fps", "RATE", $this->fpsDescription()),
            Option::flag("word-timestamps", "Keep the word times of speech-to-text JSON, YouTube timed text and podcast transcript input."),
            Option::value("track", "NUMBER", "Subtitle track of an MKV or WebM input. Needed when the file has several. \"info\" lists them."),
            Option::flag("keep-going", "Go on with the next file after a file fails. Default: stop at the first failure."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        $this->succeeded = 0;
        $this->failed    = 0;
        $this->fps       = $arguments->positiveFloat("fps");
        $arguments->positiveInt("track");

        $from             = $this->hasFormatOptions() ? $arguments->value("from") : null;
        $this->fromFormat = $from === null ? null : self::readableFormat($from);

        try {
            $this->readOptions = new ReadOptions(
                encoding: $arguments->value("encoding"),
                lenient: $arguments->has("lenient"),
                fps: $this->fps,
                wordTimestamps: $this->needsWordTimestamps($arguments),
            );
        } catch (SubtitleToolboxException $exception) {
            self::fail($exception->getMessage());
        }
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
            } catch (\Exception $exception) {
                $this->failed++;
                $console->err(self::label($input) . ": " . $exception->getMessage() . "\n");
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
        if ($input === self::DASH) {
            $subtitle = $this->readStdin($track, $console);
        } else {
            if (!is_file($input)) {
                self::fail("The file does not exist.");
            }
            if ($track === null && $this->listTracks($input, $console)) {
                return null;
            }
            $subtitle = match (true) {
                $track !== null            => Subtitle::loadTrack($input, $track, $this->readOptions),
                $this->fromFormat !== null => Subtitle::load($input, $this->fromFormat, $this->readOptions),
                default                    => Subtitle::loadAutoDetectFormat($input, $this->readOptions),
            };
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
     * Reads a file other than the input, such as a reference, with format detection and without --from and --track.
     */
    protected function loadOtherFile(string $path): Subtitle
    {
        if (!is_file($path)) {
            self::fail("$path: The file does not exist.");
        }

        try {
            return Subtitle::loadAutoDetectFormat($path, $this->readOptions);
        } catch (SubtitleToolboxException $exception) {
            return self::fail("$path: " . $exception->getMessage());
        }
    }


    /**
     * Handles an MKV or WebM input without --track. Returns false to read its only subtitle track.
     */
    protected function listTracks(string $input, Console $console): bool
    {
        return false;
    }


    private function readStdin(?int $track, Console $console): Subtitle
    {
        $content = $console->readStdin();
        if ($track !== null) {
            $path = tempnam(sys_get_temp_dir(), Application::NAME . "-");
            try {
                file_put_contents($path, $content);

                return Subtitle::loadTrack($path, $track, $this->readOptions);
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
