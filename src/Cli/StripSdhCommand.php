<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\HearingImpairedOptions;
use SubtitleToolbox\HearingImpairedRemover;
use SubtitleToolbox\Subtitle;

class StripSdhCommand extends WriteCommand
{
    private ?HearingImpairedOptions $removal = null;


    public function name(): string
    {
        return "strip-sdh";
    }


    public function summary(): string
    {
        return "Removes hearing-impaired annotations such as [DOOR SLAMS], (laughs) and JOHN:.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [options]"];
    }


    protected function details(): string
    {
        return "A cue with no text left goes. Without --output, --output-dir or --in-place, the result of one input file\n" .
               "goes to standard output.";
    }


    protected function commandOptions(): array
    {
        return [
            Option::flag("keep-square-brackets", "Keep text in square brackets, such as [DOOR SLAMS]."),
            Option::flag("keep-parentheses", "Keep text in parentheses, such as (laughs)."),
            Option::flag("keep-speaker-labels", "Keep speaker labels, such as JOHN:."),
            Option::flag("keep-music-lines", "Keep lines that hold only music symbols."),
            Option::flag("any-case-labels", "Also remove speaker labels that are not upper case, such as Baker:."),
            Option::flag("lyrics", "Remove text between two music symbols."),
            new Option("brackets", "Also remove text between this pair of characters, for example \"{}\" or \"**\". Repeatable.", "PAIR", null, true),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $brackets = [];
        foreach ($arguments->values("brackets") as $pair) {
            $characters = preg_split('//u', $pair, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($characters) !== 2) {
                self::fail("The option --brackets needs two characters, an opening and a closing one, got \"$pair\".");
            }
            $brackets[] = $characters;
        }

        $this->removal = new HearingImpairedOptions(
            squareBrackets: !$arguments->has("keep-square-brackets"),
            parentheses: !$arguments->has("keep-parentheses"),
            speakerLabels: !$arguments->has("keep-speaker-labels"),
            speakerLabelsUpperCaseOnly: !$arguments->has("any-case-labels"),
            musicOnlyLines: !$arguments->has("keep-music-lines"),
            customBrackets: $brackets,
            lyrics: $arguments->has("lyrics"),
        );
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments): void
    {
        HearingImpairedRemover::apply($subtitle, $this->removal);
    }
}
