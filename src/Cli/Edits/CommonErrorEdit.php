<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Cli\OptionsCopy;
use SubtitleToolbox\Fixing\CommonErrorFixer;
use SubtitleToolbox\Fixing\CommonErrorOptions;
use SubtitleToolbox\Fixing\OcrReplaceList;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class CommonErrorEdit extends Edit
{
    private function __construct(
        private CommonErrorOptions $options,
        private readonly bool $list,
        private readonly ?string $replaceListPath,
    ) {
    }


    public static function group(): string
    {
        return "errors";
    }


    public static function summary(): string
    {
        return "Fix spacing, punctuation and OCR errors.";
    }


    public static function options(): array
    {
        return [
            Option::flag("errors-fix", "Fix spacing, punctuation, dash, tag and OCR errors such as lt's for It's."),
            Option::value("errors-replace-list", "FILE", "Also apply this Subtitle Edit OCR replace list, an XML file, with --errors-fix."),
            Option::flag("errors-list-fixes", "Print each change of --errors-fix to standard error."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "errors-fix", ["errors-replace-list", "errors-list-fixes"]);
        if (!$arguments->has("errors-fix")) {
            return null;
        }

        return new self(
            new CommonErrorOptions(language: $arguments->value("language")),
            $arguments->has("errors-list-fixes"),
            $arguments->value("errors-replace-list"),
        );
    }


    public function loadSideFiles(): void
    {
        if ($this->replaceListPath !== null) {
            $this->options = OptionsCopy::with($this->options, [
                "replaceList" => Command::parseSideFile($this->replaceListPath, OcrReplaceList::fromSubtitleEditXml(...)),
            ]);
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        foreach (CommonErrorFixer::apply($subtitle, $this->options)->fixes as $fix) {
            if ($this->list) {
                $console->err("$label: cue " . ($fix->cueIndex + 1) . ": {$fix->rule->value}: " .
                              json_encode($fix->before, Command::JSON_FLAGS) . " -> " .
                              json_encode($fix->after, Command::JSON_FLAGS) . "\n");
            }
        }

        return $subtitle;
    }
}
