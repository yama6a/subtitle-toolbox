<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Exceptions\ParsingException;
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
        private readonly CommonErrorOptions $options,
        private readonly bool $list,
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
            new CommonErrorOptions(
                language: $arguments->value("language"),
                replaceList: self::loadReplaceList($arguments->value("errors-replace-list")),
            ),
            $arguments->has("errors-list-fixes"),
        );
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


    private static function loadReplaceList(?string $path): ?OcrReplaceList
    {
        if ($path === null) {
            return null;
        }
        try {
            return OcrReplaceList::fromSubtitleEditXml(Command::readSideFile($path));
        } catch (ParsingException $exception) {
            return Command::failSideFile($path, $exception->getMessage());
        }
    }
}
