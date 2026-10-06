<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\ReplaceTextOptions;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class ReplaceEdit extends Edit
{
    /**
     * @param list<array{string, string}> $replacements FROM and TO
     */
    private function __construct(
        private readonly array $replacements,
        private readonly bool $regex,
        private readonly bool $ignoreCase,
    ) {
    }


    public static function group(): string
    {
        return "replace";
    }


    public static function summary(): string
    {
        return "Replace words or patterns in the text.";
    }


    public static function options(): array
    {
        return [
            new Option("replace", "Replace FROM with TO in the text between tags, for example --replace colour=color. Repeatable.", "FROM=TO", null, true),
            Option::flag("replace-regex", "Read each FROM of --replace as a regular expression with delimiters, such as /\\.{4,}/. TO can use \$1."),
            Option::flag("replace-ignore-case", "Match FROM of --replace in any case."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "replace", ["replace-regex", "replace-ignore-case"]);
        if (!$arguments->has("replace")) {
            return null;
        }

        $replacements = [];
        foreach ($arguments->values("replace") as $pair) {
            if (!str_contains($pair, "=") || str_starts_with($pair, "=")) {
                Command::fail("The option --replace needs FROM=TO, got \"$pair\".");
            }
            [$from, $to] = explode("=", $pair, 2);
            if ($arguments->has("replace-regex") && @preg_match($from, "") === false) {
                Command::fail("The option --replace has an invalid regular expression: $from");
            }
            $replacements[] = [$from, $to];
        }

        return new self($replacements, $arguments->has("replace-regex"), $arguments->has("replace-ignore-case"));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        foreach ($this->replacements as [$from, $to]) {
            $subtitle->replaceText($from, $to, new ReplaceTextOptions($this->regex, !$this->ignoreCase));
        }

        return $subtitle;
    }
}
