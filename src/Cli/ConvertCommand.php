<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Cli\Edits\AssOutput;
use SubtitleToolbox\Cli\Edits\Edit;
use SubtitleToolbox\Cli\Edits\EditPipeline;
use SubtitleToolbox\Cli\Edits\MaskingEdit;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class ConvertCommand extends WriteCommand
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
               "as \"season1/*.srt\", or -.";
    }


    /**
     * Prints the common options and the group list, the options of the group $topic, or with "all" every option. A
     * $topic with a dot or a slash, or that names a file, is no group, so the help is the one without $topic.
     */
    public function help(?string $topic = null): string
    {
        $groups = [];
        foreach ([...EditPipeline::edits(), AssOutput::class] as $class) {
            $groups[$class::group()] = $class;
        }

        // In "convert in.srt -h out.srt" the word after -h is a file, not a group.
        if ($topic !== null && $topic !== "all" && !isset($groups[$topic]) && (strpbrk($topic, "./\\") !== false || file_exists($topic))) {
            $topic = null;
        }

        $common = $this->helpHeader() . "\nOptions:\n" . self::optionList([...$this->commonOptions(), Option::flag("help", "Show this help.", "h")]);
        if ($topic === null) {
            $width = max(array_map("strlen", array_keys($groups)));
            $list  = "";
            foreach ($groups as $name => $class) {
                $list .= "  " . str_pad($name, $width) . "  " . $class::summary() . "\n";
            }

            return "$common\nOption groups, in the order that convert runs their edits:\n$list\n" .
                   "Run \"" . Application::NAME . " convert --help GROUP\" for the options of a group,\n" .
                   "or \"" . Application::NAME . " convert --help all\" for all options.\n";
        }
        if ($topic === "all") {
            return $common . implode("", array_map(fn (string $class): string => "\n" . self::groupHelp($class), $groups));
        }
        if (!isset($groups[$topic])) {
            self::fail("Unknown option group \"$topic\". The groups are " . implode(", ", array_keys($groups)) . ", and all for every option.");
        }

        return self::groupHelp($groups[$topic]);
    }


    /**
     * @param class-string<Edit>|class-string<AssOutput> $class
     */
    private static function groupHelp(string $class): string
    {
        return $class::group() . ": " . $class::summary() . "\n" . self::optionList($class::options());
    }


    /**
     * @return list<Option>
     */
    private function commonOptions(): array
    {
        return [...$this->outputOptions(), ...$this->inputOptions(), self::languageOption()];
    }


    private static function languageOption(): Option
    {
        return Option::value("language", "CODE", "Language for --case and --errors-fix, for example en, de-AT or tr. " .
                                               "--errors-fix takes the language of the input without it.");
    }


    protected function fpsDescription(): string
    {
        return "Sets --input-fps, --output-fps and --video-fps. Each of them overrides it.";
    }


    protected function commandOptions(): array
    {
        $options = [self::languageOption()];
        foreach ([...EditPipeline::edits(), AssOutput::class] as $class) {
            array_push($options, ...$class::options());
        }

        return $options;
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
        return parent::needsWordTimestamps($arguments) || $arguments->has("structure-resegment") || $arguments->has("karaoke")
            || $arguments->has("ass-karaoke-tag");
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        if (count($inputs) > 1 && ($arguments->has("mute-edl") || $arguments->has("mute-filter"))) {
            self::fail("--mute-edl and --mute-filter take one input file, got " . count($inputs) . ".");
        }

        parent::checkInputs($inputs, $arguments);
    }


    protected function sideOutputs(): array
    {
        return $this->edits->find(MaskingEdit::class)?->outputPaths() ?? [];
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
