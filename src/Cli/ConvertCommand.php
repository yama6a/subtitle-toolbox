<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Subtitle;

class ConvertCommand extends WriteCommand
{
    public function name(): string
    {
        return "convert";
    }


    public function summary(): string
    {
        return "Converts subtitle files to another format.";
    }


    protected function usageLines(): array
    {
        return ["<input> <output> [options]", "<input>... --to FORMAT [options]"];
    }


    protected function details(): string
    {
        return "With two arguments and no --to, the second argument is the output file, and its extension sets the format.\n" .
               "Without --output or --output-dir, each output file goes next to its input file, with the extension of\n" .
               "the output format. An input argument can be a file, a directory, a glob such as \"season1/*.srt\", or -.";
    }


    protected function commandOptions(): array
    {
        return [Option::flag("strip-tags", "Remove all formatting tags, such as <i> and <font>, from the cue text.")];
    }


    protected function allowsInPlace(): bool
    {
        return false;
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

        if ($this->toFormat === null && ($this->output === null || $this->output === self::DASH)) {
            self::fail("Pass --to FORMAT or an output file.");
        }
    }


    protected function defaultTarget(string $input, string $fileName): string
    {
        $directory = dirname($input);

        return $directory === "." && !str_starts_with($input, ".") ? $fileName : "$directory/$fileName";
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        if ($arguments->has("strip-tags")) {
            $subtitle->stripFormatting();
        }
    }


    private function usesPositionalOutput(Arguments $arguments): bool
    {
        return count($arguments->positionals) === 2 && !$arguments->has("to")
            && !$arguments->has("output") && !$arguments->has("output-dir");
    }
}
