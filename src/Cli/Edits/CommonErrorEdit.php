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
            Option::flag("fix-common-errors", "Fix spacing, punctuation, dash, tag and OCR errors such as lt's for It's."),
            Option::value("fix-replace-list", "FILE", "Also apply this Subtitle Edit OCR replace list, an XML file, with --fix-common-errors."),
            Option::flag("fix-list", "Print each change of --fix-common-errors to standard error."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "fix-common-errors", ["fix-replace-list", "fix-list"]);
        if (!$arguments->has("fix-common-errors")) {
            return null;
        }

        return new self(
            new CommonErrorOptions(
                language: $arguments->value("language"),
                replaceList: self::loadReplaceList($arguments->value("fix-replace-list")),
            ),
            $arguments->has("fix-list"),
        );
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        foreach (CommonErrorFixer::apply($subtitle, $this->options)->fixes as $fix) {
            if ($this->list) {
                $console->err("$label: cue " . ($fix->cueIndex + 1) . ": $fix->rule: " .
                              json_encode($fix->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . " -> " .
                              json_encode($fix->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
        }

        return $subtitle;
    }


    private static function loadReplaceList(?string $path): ?OcrReplaceList
    {
        if ($path === null) {
            return null;
        }
        $xml = is_file($path) ? @file_get_contents($path) : false;
        if ($xml === false) {
            Command::fail("Cannot read the replace list $path.");
        }

        try {
            return OcrReplaceList::fromSubtitleEditXml($xml);
        } catch (ParsingException $exception) {
            return Command::fail("$path: " . $exception->getMessage());
        }
    }
}
