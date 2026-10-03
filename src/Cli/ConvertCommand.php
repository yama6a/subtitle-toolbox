<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Cli\Edits\AssOutput;
use SubtitleToolbox\Cli\Edits\EditPipeline;
use SubtitleToolbox\Cli\Edits\MaskingEdit;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Subtitle;

class ConvertCommand extends WriteCommand
{
    private EditPipeline $edits;

    private ?AssOutput $assOutput = null;


    public function name(): string
    {
        return "convert";
    }


    public function summary(): string
    {
        return "Converts subtitle files to another format, and runs OCR, text, structure and timing edits on the way.";
    }


    protected function usageLines(): array
    {
        return ["<input> <output> [options]", "<input>... [--to FORMAT] [options]"];
    }


    protected function details(): string
    {
        return "With two arguments and no --to, --output, --output-dir or --in-place, the second argument is the output\n" .
               "file, and its extension sets the format. Without --to, the output keeps the input format. Without --output,\n" .
               "--output-dir or --in-place, one input file goes to standard output, and several go next to their input\n" .
               "files, with the extension of the output format. An input argument can be a file, a directory, a glob such\n" .
               "as \"season1/*.srt\", or -.\n\n" .
               "convert runs the edits in this order: --ocr, --forced-only, --fix-common-errors, --sdh, --replace,\n" .
               "--strip-tags, --case, --speakers, --mask-words, the structure fixes from --fix-resegment to\n" .
               "--fix-merge-duplicates, --shift, --scale, --from-fps and --to-fps, --snap-shot-changes, --fix-overlaps,\n" .
               "--fix-min-duration, --karaoke.";
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps, --output-fps and --video-fps. Each of them overrides it.";
    }


    protected function commandOptions(): array
    {
        return [...EditPipeline::options(), ...AssOutput::options()];
    }


    protected function explicitOutput(Arguments $arguments): ?string
    {
        if ($this->usesPositionalOutput($arguments)) {
            return $arguments->positionals[1];
        }

        return parent::explicitOutput($arguments);
    }


    protected function inputArguments(Arguments $arguments): array
    {
        return $this->usesPositionalOutput($arguments) ? [$arguments->positionals[0]] : $arguments->positionals;
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->edits     = EditPipeline::fromArguments($arguments);
        $this->assOutput = AssOutput::fromArguments($arguments);
    }


    protected function needsWordTimestamps(Arguments $arguments): bool
    {
        return parent::needsWordTimestamps($arguments) || $arguments->has("fix-resegment") || $arguments->has("karaoke")
            || $arguments->has("ass-karaoke-tag");
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        parent::checkInputs($inputs, $arguments);

        if (count($inputs) > 1 && ($arguments->has("mute-edl") || $arguments->has("mute-filter"))) {
            self::fail("--mute-edl and --mute-filter take one input file, got " . count($inputs) . ".");
        }
    }


    protected function process(string $input, Subtitle $subtitle, Format $format, Arguments $arguments, Console $console): void
    {
        parent::process($input, $subtitle, $format, $arguments, $console);

        foreach ($this->edits->find(MaskingEdit::class)?->writeMuteFiles() ?? [] as $path) {
            $this->report($console, self::label($input) . " -> $path\n");
        }
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        return $this->edits->apply($subtitle, $console, self::label($input));
    }


    protected function commandFormatterOptions(Format $outputFormat, Arguments $arguments): ?FormatWriteOptions
    {
        return $this->assOutput?->formatOptions($outputFormat);
    }


    private function usesPositionalOutput(Arguments $arguments): bool
    {
        return count($arguments->positionals) === 2 && !$arguments->has("to")
            && !$arguments->has("output") && !$arguments->has("output-dir") && !$arguments->has("in-place");
    }
}
