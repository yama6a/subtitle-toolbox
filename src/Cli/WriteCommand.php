<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Container\Matroska\MatroskaReader;
use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Exceptions\SubtitleToolboxException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttWriteOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

/**
 * Changes each input subtitle with transform() and writes it to a file or to standard output.
 *
 * @internal
 */
abstract class WriteCommand extends FileCommand
{
    private const LINE_ENDINGS = ["lf" => LineEnding::Lf, "crlf" => LineEnding::Crlf];

    // The options that name a file the command reads besides its inputs. No output may overwrite one.
    private const READ_OPTIONS = ["reference", "silence-log", "mask-words", "snap-shot-changes", "errors-replace-list", "ocr-database"];

    protected ?Format $toFormat = null;

    protected ?string $output = null;

    protected bool $dataOnStdout = false;

    /** @var list<string> the real paths of the files that the command reads */
    private array $readPaths = [];

    /** @var array<string, string> input => real path of its output file, where checkInputs() could tell it */
    private array $plannedTargets = [];

    protected ?float $outputFps = null;

    private WriteOptions $writeOptions;


    public function options(): array
    {
        return [...$this->commandOptions(), ...$this->outputOptions(), ...$this->inputOptions()];
    }


    /**
     * Returns the subtitle to write, changed in place or new.
     */
    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        return $subtitle;
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps and --output-fps. Each of them overrides it.";
    }


    protected function toDescription(): string
    {
        return "Output format. Default: the input format.";
    }


    /**
     * @return list<Option>
     */
    protected function outputOptions(): array
    {
        return [
            Option::value("to", "FORMAT", $this->toDescription()),
            Option::value("output", "FILE", "Write the output of one input to this file in place of standard output. The file must not exist.", "o"),
            Option::value("output-dir", "DIR", "Write each output into this directory, with the base name of its input. Several inputs need it."),
            Option::value("output-fps", "RATE", "Frame rate of MicroDVD and iTT output. Default: the frame rate of a MicroDVD or iTT input."),
            Option::value("line-ending", "lf|crlf", "Line ending of the output. Default: lf."),
            Option::flag("bom", "Start the output with a UTF-8 BOM."),
            Option::flag("no-bom", "Write no UTF-8 BOM. Default: the BOM rule of the output format."),
            Option::flag("skip-image-cues", "Leave out image cues without text, for example from PGS or VobSub, in place of failing."),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $to              = $arguments->value("to");
        $this->toFormat  = $to === null ? null : self::writableFormat($to);
        $this->output    = $arguments->value("output");
        $this->outputFps = self::rate($arguments, "output-fps");

        if ($this->output !== null && $arguments->has("output-dir")) {
            self::fail("Pass only one of --output and --output-dir.");
        }
        if ($this->output !== null && $this->toFormat !== null && $this->output !== self::DASH) {
            $named = Format::fromPath($this->output);
            if ($named !== null && !in_array(strtolower(pathinfo($this->output, PATHINFO_EXTENSION)), $this->toFormat->extensions(), true)) {
                self::fail("The extension of --output $this->output names the format $named->value, not the --to format {$this->toFormat->value}.");
            }
        }
        if ($arguments->has("bom") && $arguments->has("no-bom")) {
            self::fail("Pass only one of --bom and --no-bom.");
        }

        $lineEnding         = $arguments->value("line-ending") ?? "lf";
        $this->writeOptions = new WriteOptions(
            lineEnding: self::LINE_ENDINGS[strtolower($lineEnding)]
                ?? self::fail("The option --line-ending must be lf or crlf, got \"$lineEnding\"."),
            bom: $arguments->has("bom") || $arguments->has("no-bom") ? $arguments->has("bom") : null,
            skipImageCues: $arguments->has("skip-image-cues"),
        );
    }


    /**
     * Plans every output file before the first read. It fails when a file exists, is a file that the command reads,
     * or is the output of two inputs.
     */
    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        $count = count($inputs);
        if ($this->output !== null && $count > 1) {
            self::fail("--output takes one input file, got $count. Pass --output-dir DIR for several files.");
        }
        if ($count > 1 && !$arguments->has("output-dir")) {
            self::fail("$count input files need --output-dir DIR. One input file goes to standard output or to -o FILE.");
        }
        if ($arguments->has("output-dir") && in_array(self::DASH, $inputs, true)) {
            self::fail("Standard input has no file name for --output-dir. Pass -o FILE.");
        }

        $this->readPaths    = self::realReadPaths([
            ...$this->readPaths($inputs, $arguments),
            ...array_filter(array_map($arguments->value(...), self::READ_OPTIONS)),
        ]);
        $this->dataOnStdout = $this->output === self::DASH || in_array(self::DASH, $inputs, true)
            || ($this->output === null && !$arguments->has("output-dir"));

        $this->plannedTargets = [];
        $writers              = [];
        foreach ($inputs as $input) {
            $target = $this->plannedTarget($input, $arguments);
            if ($target === null || $target === self::DASH) {
                continue;
            }
            $real = self::realTarget($target);
            if (isset($writers[$real])) {
                self::fail("$writers[$real] and $input would both write $target. Pass them in two runs.");
            }
            $this->checkNewFile($target, $real, "The output $target");
            $writers[$real]               = $input;
            $this->plannedTargets[$input] = $real;
        }
        foreach ($this->sideOutputs() as $option => $path) {
            $real = self::realTarget($path);
            if (isset($writers[$real])) {
                self::fail("The --$option file $path is also the output of $writers[$real].");
            }
            $this->checkNewFile($path, $real, "The --$option file $path");
            $writers[$real] = "--$option";
        }
    }


    /**
     * Fails when the file $path, with the real path $real, is a file that the command reads or exists.
     */
    private function checkNewFile(string $path, string $real, string $name): void
    {
        if (in_array($real, $this->readPaths, true)) {
            self::fail("$name is a file that the command reads. Pass another output file or directory.");
        }
        if (OutputFiles::exists($path)) {
            self::fail("$name exists. The tool never overwrites a file. Remove it, or pass another output file or directory.");
        }
    }


    /**
     * Returns the files that the command writes besides the subtitles, by option name.
     *
     * @return array<string, string>
     */
    protected function sideOutputs(): array
    {
        return [];
    }


    /**
     * Returns the output of $input, or null when only the read can tell it.
     */
    private function plannedTarget(string $input, Arguments $arguments): ?string
    {
        if ($input === self::DASH || $this->output !== null || !$arguments->has("output-dir")) {
            return $this->target($input, null, $arguments);
        }
        $outputFormat = $this->toFormat;
        if ($outputFormat === null) {
            $outputFormat = $this->peekFormat($input, $this->inputTrack($arguments));
            $outputFormat = $outputFormat?->canWrite() ? $outputFormat : null;
        }

        return $outputFormat === null ? null : $this->target($input, $outputFormat, $arguments);
    }


    /**
     * Returns the format that the read of $input will find, or null when only the read can tell.
     */
    private function peekFormat(string $input, ?int $track): ?Format
    {
        try {
            if (!is_file($input)) {
                return null;
            }
            if ($track !== null || self::isMatroska($input)) {
                $reader   = MatroskaReader::open($input);
                $tracks   = $reader->getSubtitleTracks();
                $track  ??= count($tracks) === 1 ? $tracks[0]->number : null;

                return $track === null ? null : $reader->trackFormat($track);
            }
            if ($this->fromFormat !== null) {
                return $this->fromFormat;
            }
            // The rule of Subtitle::loadAutoDetectFormat().
            $byExtension = Format::fromPath($input);
            $detected    = Format::detect(StringHelpers::convertToUtf8((string)file_get_contents($input), $this->readOptions->encoding));

            return $detected === Format::Ttml && $byExtension === Format::Itt ? Format::Itt : $detected ?? $byExtension;
        } catch (SubtitleToolboxException) {
            return null;
        }
    }


    private static function isMatroska(string $path): bool
    {
        $file = is_file($path) ? @fopen($path, "rb") : false;
        if ($file === false) {
            return false;
        }
        $magic = fread($file, strlen(MatroskaReader::EBML_MAGIC));
        fclose($file);

        return $magic === MatroskaReader::EBML_MAGIC;
    }


    /**
     * Returns the real paths of $paths that exist, with the other file of each VobSub .idx and .sub pair.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function realReadPaths(array $paths): array
    {
        $real = [];
        foreach ($paths as $path) {
            $realPath = $path === self::DASH ? false : realpath($path);
            if ($realPath === false) {
                continue;
            }
            $real[]    = $realPath;
            $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
            if ($extension !== "idx" && $extension !== "sub") {
                continue;
            }
            $pair = strtolower(pathinfo($realPath, PATHINFO_FILENAME)) . ($extension === "idx" ? ".sub" : ".idx");
            foreach (scandir(dirname($realPath)) ?: [] as $name) {
                if (strtolower($name) === $pair) {
                    $real[] = dirname($realPath) . DIRECTORY_SEPARATOR . $name;
                }
            }
        }

        return array_values(array_unique($real));
    }


    /**
     * Returns the files that the command reads. No output may be one of them.
     *
     * @param list<string> $inputs
     *
     * @return list<string>
     */
    protected function readPaths(array $inputs, Arguments $arguments): array
    {
        return $inputs;
    }


    protected function report(Console $console, string $line): void
    {
        $this->dataOnStdout ? $console->err($line) : $console->out($line);
    }


    private static function writableFormat(string $nameOrExtension): Format
    {
        $format = self::findFormat($nameOrExtension);
        if (!$format->canWrite()) {
            self::fail("The format $format->value can be read but not written.");
        }

        return $format;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $outputFormat = $this->toFormat ?? ($format->canWrite() ? $format : null)
            ?? self::fail("The format $format->value can be read but not written. Pass --to with another format.");
        $target = $this->target($input, $outputFormat, $arguments);
        $this->checkTarget($input, $target);

        $subtitle = $this->transform($subtitle, $arguments, $console, $input);

        try {
            $content = $subtitle->toString($outputFormat, $this->formatterOptions($outputFormat, $arguments));
        } catch (ImageCueWithoutTextException) {
            self::fail("The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.");
        }

        if ($target === self::DASH) {
            $console->out($content);

            return;
        }

        $this->outputFiles->create($target, $content);
        $this->report($console, self::label($input) . " -> $target\n");
    }


    /**
     * Checks an output that checkInputs() could not plan, because only the read told the format of the input.
     */
    private function checkTarget(string $input, string $target): void
    {
        if ($target === self::DASH) {
            return;
        }
        $real = self::realTarget($target);
        if ($real === ($this->plannedTargets[$input] ?? null)) {
            return;
        }
        if (in_array($real, $this->plannedTargets, true)) {
            self::fail("The output $target is also the output of another input.");
        }
        $this->checkNewFile($target, $real, "The output $target");
    }


    /**
     * Returns formatter options of the command for the output format.
     */
    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return null;
    }


    /**
     * Returns the output of $input: standard output, the --output file, or the base name of the input in --output-dir
     * with the extension of $outputFormat. $outputFormat is null only for standard output and --output.
     */
    private function target(string $input, ?Format $outputFormat, Arguments $arguments): string
    {
        if ($this->output !== null) {
            return $this->output;
        }
        $directory = $arguments->value("output-dir");
        if ($input === self::DASH || $directory === null || $outputFormat === null) {
            return self::DASH;
        }

        $fileName   = basename($input);
        $extensions = $outputFormat->extensions();
        if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extensions, true)) {
            $fileName = pathinfo($fileName, PATHINFO_FILENAME) . "." . $extensions[0];
        }

        return rtrim($directory, "/\\") . "/$fileName";
    }


    private function formatterOptions(Format $outputFormat, Arguments $arguments): WriteOptions
    {
        $format = match (true) {
            $this->outputFps !== null && $outputFormat === Format::MicroDvd => new MicroDvdWriteOptions(frameRate: $this->outputFps),
            $this->outputFps !== null && $outputFormat === Format::Itt      => new IttWriteOptions(frameRate: $this->outputFps),
            default                                                         => $this->commandFormatterOptions($outputFormat, $arguments),
        };

        return new WriteOptions(
            lineEnding: $this->writeOptions->lineEnding,
            bom: $this->writeOptions->bom,
            skipImageCues: $this->writeOptions->skipImageCues,
            format: $format,
        );
    }
}
