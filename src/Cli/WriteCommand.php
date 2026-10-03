<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\ImageCueWithoutTextException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Formatters\Options\IttOptions;
use SubtitleToolbox\Formatters\Options\MicroDvdOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

/**
 * Changes each input subtitle with transform() and writes it to a file or to standard output.
 */
abstract class WriteCommand extends FileCommand
{
    private const LINE_ENDINGS = ["lf" => LineEnding::Lf, "crlf" => LineEnding::Crlf];

    protected ?Format $toFormat = null;

    protected ?string $output = null;

    protected bool $dataOnStdout = false;

    protected ?float $outputFps = null;

    private WriteOptions $writeOptions;


    abstract protected function transform(Subtitle $subtitle, Arguments $arguments): void;


    public function options(): array
    {
        return [...$this->commandOptions(), ...$this->outputOptions(), ...$this->inputOptions()];
    }


    protected function allowsInPlace(): bool
    {
        return true;
    }


    /**
     * Returns the output path when no --output, --output-dir or --in-place is given, or "-" for standard output.
     */
    protected function defaultTarget(string $input, string $fileName): string
    {
        return self::DASH;
    }


    protected function explicitOutput(Arguments $arguments): ?string
    {
        return $arguments->value("output");
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps and --output-fps. Each of them overrides it.";
    }


    /**
     * @return list<Option>
     */
    protected function outputOptions(): array
    {
        $options   = [Option::value("to", "FORMAT", "Output format. Default: the format of the --output extension, else the input format.")];
        $options[] = Option::value("output", "PATH", "Output file, or - for standard output. Takes one input file.", "o");
        $options[] = Option::value("output-dir", "DIR", "Write each output file into this directory. Creates it when it is missing.");
        if ($this->allowsInPlace()) {
            $options[] = Option::flag("in-place", "Overwrite each input file.");
        }

        return [
            ...$options,
            Option::flag("force", "Overwrite output files that exist."),
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
        $this->output    = $this->explicitOutput($arguments);
        $this->outputFps = self::rate($arguments, "output-fps");

        $targets = array_filter([$this->output !== null, $arguments->has("output-dir"), $arguments->has("in-place")]);
        if (count($targets) > 1) {
            self::fail("Pass only one of --output, --output-dir and --in-place.");
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


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if ($this->output !== null && count($inputs) > 1) {
            self::fail("--output takes one input file, got " . count($inputs) . ". Use --output-dir for several files.");
        }

        $toStdout = $this->output === self::DASH || in_array(self::DASH, $inputs, true);
        if ($this->output === null && !$arguments->has("output-dir") && !$arguments->has("in-place")) {
            $defaultsToStdout = array_filter($inputs, fn (string $input): bool =>
                $input !== self::DASH && $this->defaultTarget($input, basename($input)) === self::DASH);
            if ($defaultsToStdout !== [] && count($inputs) > 1) {
                self::fail("Pass --output-dir or --in-place for several input files.");
            }
            $toStdout = $toStdout || $defaultsToStdout !== [];
        }
        $this->dataOnStdout = $toStdout;
    }


    protected function report(Console $console, string $line): void
    {
        $this->dataOnStdout ? $console->err($line) : $console->out($line);
    }


    public static function writableFormat(string $nameOrExtension): Format
    {
        $format = self::findFormat($nameOrExtension);
        if (!$format->canWrite()) {
            self::fail("The format $format->value can be read but not written.");
        }

        return $format;
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        $this->transform($subtitle, $arguments);

        $outputFormat = $this->outputFormat($format);
        $target       = $this->target($input, $format, $outputFormat, $arguments);

        try {
            $content = $subtitle->toString($outputFormat, $this->formatterOptions($outputFormat, $arguments));
        } catch (ImageCueWithoutTextException) {
            self::fail("The file holds image cues without text. Run OCR on them first, or pass --skip-image-cues.");
        }

        if ($target === self::DASH) {
            $console->out($content);

            return;
        }

        $this->write($input, $target, $content, $arguments);
        $this->report($console, self::label($input) . " -> $target\n");
    }


    /**
     * Returns formatter options of the command for the output format.
     */
    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return null;
    }


    private function outputFormat(Format $inputFormat): Format
    {
        if ($this->toFormat !== null) {
            return $this->toFormat;
        }

        if ($this->output !== null && $this->output !== self::DASH) {
            $extension = strtolower(pathinfo($this->output, PATHINFO_EXTENSION));
            if (in_array($extension, $inputFormat->extensions(), true) && $inputFormat->canWrite()) {
                return $inputFormat;
            }
            $byExtension = Format::fromPath($this->output);
            if ($byExtension?->canWrite()) {
                return $byExtension;
            }
        }
        if ($inputFormat->canWrite()) {
            return $inputFormat;
        }

        return self::fail("The format $inputFormat->value can be read but not written. Pass --to with another format.");
    }


    private function target(string $input, Format $inputFormat, Format $outputFormat, Arguments $arguments): string
    {
        if ($this->output !== null) {
            return $this->output;
        }
        if ($input === self::DASH) {
            return self::DASH;
        }
        if ($arguments->has("in-place")) {
            return $input;
        }

        $fileName   = basename($input);
        $extensions = $outputFormat->extensions();
        if (($outputFormat !== $inputFormat || $this->fromContainer) && !in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extensions, true)) {
            $fileName = pathinfo($fileName, PATHINFO_FILENAME) . "." . $extensions[0];
        }

        $directory = $arguments->value("output-dir");

        return $directory === null ? $this->defaultTarget($input, $fileName) : rtrim($directory, "/\\") . "/$fileName";
    }


    private function write(string $input, string $target, string $content, Arguments $arguments): void
    {
        $isInput = $input !== self::DASH && file_exists($target) && realpath($target) === realpath($input);
        if ($isInput && !$arguments->has("in-place") && !$arguments->has("force")) {
            self::fail("The output $target is the input file. Pass " . ($this->allowsInPlace() ? "--in-place or " : "") .
                       "--force to overwrite it.");
        }
        if (!$isInput && file_exists($target) && !$arguments->has("force")) {
            self::fail("$target exists. Pass --force to overwrite it.");
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
            self::fail("Cannot create the directory $directory.");
        }
        if (@file_put_contents($target, $content) === false) {
            self::fail("Cannot write $target.");
        }
    }


    private function formatterOptions(Format $outputFormat, Arguments $arguments): WriteOptions
    {
        $format = match (true) {
            $this->outputFps !== null && $outputFormat === Format::MicroDvd => new MicroDvdOptions(frameRate: $this->outputFps),
            $this->outputFps !== null && $outputFormat === Format::Itt      => new IttOptions(frameRate: $this->outputFps),
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
