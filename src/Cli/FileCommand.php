<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\FormatDetector;
use SubtitleToolbox\FormatRegistry;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Parsers\SubtitleParser;
use SubtitleToolbox\Parsers\VobSubParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

/**
 * Reads each input file, a glob or the files of a directory, and runs process() on it.
 */
abstract class FileCommand extends Command
{
    public const DASH = "-";

    protected ?string $fromFormat = null;

    protected ?float $fps = null;

    protected int $succeeded = 0;

    protected int $failed = 0;


    /**
     * @return list<Option>
     */
    abstract protected function commandOptions(): array;


    abstract protected function process(string $input, Subtitle $subtitle, string $format, Arguments $arguments, Console $console): void;


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
            $options[] = Option::value("from", "FORMAT", "Input format. Default: detected from the content, else taken from the file extension.");
        }

        return [
            ...$options,
            Option::value("encoding", "NAME", "Encoding of the input, for example Windows-1252. Default: UTF-8, or the encoding of a UTF-16 or UTF-32 BOM."),
            Option::flag("lenient", "Skip or repair broken cues in SubRip, WebVTT and SBV input, and print a warning for each."),
            Option::value("fps", "RATE", $this->fpsDescription()),
            Option::flag("keep-going", "Go on with the next file after a file fails. Default: stop at the first failure."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        $this->succeeded = 0;
        $this->failed    = 0;
        $this->fps       = $arguments->positiveFloat("fps");

        $from             = $this->hasFormatOptions() ? $arguments->value("from") : null;
        $this->fromFormat = $from === null ? null : self::readableFormat($from);
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
                [$subtitle, $format] = $this->read($input, $arguments, $console);
                $this->process($input, $subtitle, $format, $arguments, $console);
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
     * Returns the format name for a name or an extension that the library can read.
     */
    public static function readableFormat(string $nameOrExtension): string
    {
        $format = FormatRegistry::find($nameOrExtension)
            ?? self::fail("Unknown format \"$nameOrExtension\". Run \"" . Application::NAME . " formats\" for the list.");
        if (FormatRegistry::parserClass($format) === null) {
            self::fail("The format $format can be written but not read.");
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
            $format = FormatRegistry::forPath($name);
            if (!is_file($path) || $format === null || FormatRegistry::parserClass($format) === null) {
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
     * @return array{Subtitle, string}
     */
    protected function read(string $input, Arguments $arguments, Console $console): array
    {
        $content = StringHelpers::convertToUtf8($this->readFile($input, $console), $arguments->value("encoding"));
        $format  = $this->inputFormat($input, $content);

        if ($format === "vobsub" && $input === self::DASH) {
            self::fail("VobSub needs the path of the .idx file. Standard input does not work.");
        }

        $parser = $this->createParser($format, $content)->setLenient($arguments->has("lenient"));
        if ($format === "vobsub") {
            $subtitle = $parser->parse($this->readFile(substr($input, 0, -strlen(pathinfo($input, PATHINFO_EXTENSION))) . "sub", $console));
        } else {
            $subtitle = $parser->parse($content);
        }

        foreach ($parser->getWarnings() as $warning) {
            $console->err(self::label($input) . ": line $warning->lineNumber: $warning->message ($warning->action)\n");
        }

        return [$subtitle, $format];
    }


    protected function readFile(string $path, Console $console): string
    {
        if ($path === self::DASH) {
            return $console->readStdin();
        }
        if (!is_file($path)) {
            self::fail("The file does not exist.");
        }
        $content = @file_get_contents($path);

        return $content === false ? self::fail("Cannot read the file.") : $content;
    }


    private function inputFormat(string $input, string $content): string
    {
        if ($this->fromFormat !== null) {
            return $this->fromFormat;
        }

        $byExtension     = $input === self::DASH ? null : FormatRegistry::forPath($input);
        $extensionParser = $byExtension === null ? null : FormatRegistry::parserClass($byExtension);
        $detectedParser  = FormatDetector::detect($content);

        if ($detectedParser !== null) {
            // Detection returns TtmlParser for an iTT file. IttParser reads the same cues and keeps the iTT timing.
            if ($extensionParser !== null && is_subclass_of($extensionParser, $detectedParser)) {
                return $byExtension;
            }

            return FormatRegistry::forParser($detectedParser) ?? self::fail("No format has the parser $detectedParser.");
        }
        if ($extensionParser !== null) {
            return $byExtension;
        }

        return self::fail("The format is unknown." . ($this->hasFormatOptions() ? " Pass --from." : ""));
    }


    private function createParser(string $format, string $content): SubtitleParser
    {
        $class = FormatRegistry::parserClass($format);

        return match ($class) {
            MicroDvdParser::class => new MicroDvdParser($this->fps),
            VobSubParser::class   => new VobSubParser($content),
            default               => new $class(),
        };
    }
}
