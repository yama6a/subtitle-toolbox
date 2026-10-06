<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\HearingImpaired\HearingImpairedOptions;
use SubtitleToolbox\HearingImpaired\HearingImpairedRemover;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class SdhEdit extends Edit
{
    private function __construct(private readonly HearingImpairedOptions $options)
    {
    }


    public static function group(): string
    {
        return "sdh";
    }


    public static function summary(): string
    {
        return "Remove hearing-impaired annotations.";
    }


    public static function options(): array
    {
        return [
            Option::flag("sdh", "Remove hearing-impaired annotations such as [DOOR SLAMS], (laughs) and JOHN:. A cue with no text left goes."),
            Option::flag("sdh-keep-square-brackets", "Keep text in square brackets, such as [DOOR SLAMS]."),
            Option::flag("sdh-keep-parentheses", "Keep text in parentheses, such as (laughs)."),
            Option::flag("sdh-keep-speaker-labels", "Keep speaker labels, such as JOHN:."),
            Option::flag("sdh-keep-music-lines", "Keep lines that hold only music symbols."),
            Option::flag("sdh-any-case-labels", "Also remove speaker labels that are not upper case, such as Baker:."),
            Option::flag("sdh-lyrics", "Remove text between two music symbols."),
            Option::repeatable("sdh-brackets", "PAIR", "Also remove text between this pair of characters, for example \"{}\" or \"**\". Repeatable."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "sdh", ["sdh-keep-square-brackets", "sdh-keep-parentheses", "sdh-keep-speaker-labels", "sdh-keep-music-lines",
                                        "sdh-any-case-labels", "sdh-lyrics", "sdh-brackets"]);
        if (!$arguments->has("sdh")) {
            return null;
        }

        $brackets = [];
        foreach ($arguments->values("sdh-brackets") as $pair) {
            $characters = preg_split('//u', $pair, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($characters) !== 2) {
                Command::fail("The option --sdh-brackets needs two characters, an opening and a closing one, got \"$pair\".");
            }
            $brackets[] = $characters;
        }

        return new self(new HearingImpairedOptions(
            squareBrackets: !$arguments->has("sdh-keep-square-brackets"),
            parentheses: !$arguments->has("sdh-keep-parentheses"),
            speakerLabels: !$arguments->has("sdh-keep-speaker-labels"),
            speakerLabelsUpperCaseOnly: !$arguments->has("sdh-any-case-labels"),
            musicOnlyLines: !$arguments->has("sdh-keep-music-lines"),
            customBrackets: $brackets,
            lyrics: $arguments->has("sdh-lyrics"),
        ));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        HearingImpairedRemover::apply($subtitle, $this->options);

        return $subtitle;
    }
}
