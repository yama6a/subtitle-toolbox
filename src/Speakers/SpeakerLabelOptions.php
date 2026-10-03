<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SpeakerLabelOptions
{
    /** @var list<string> */
    public readonly array $colours;


    /**
     * Creates the settings for SpeakerLabels::apply().
     *
     * $from SpeakerStyle::Prefix turns a label such as "JOHN: " at the start of a line, or after its dialogue dash,
     * into <v John>. $upperCaseOnly applies to it. $rename maps speaker names of the <v> tags to new names, for example
     * ["SPEAKER_00" => "Anna"]. $to writes the <v> tags in another style. $upperCase and $separator apply to
     * SpeakerStyle::Prefix, $dash to SpeakerStyle::DialogueDashes and $colours to SpeakerStyle::Colours. After the last
     * colour, the list starts again.
     *
     * @param array<string, string> $rename
     * @param list<string>          $colours
     */
    public function __construct(
        public readonly ?SpeakerStyle $from = null,
        public readonly ?SpeakerStyle $to = null,
        public readonly array $rename = [],
        public readonly bool $upperCase = true,
        public readonly string $separator = ": ",
        public readonly string $dash = "- ",
        array $colours = SpeakerLabels::BBC_COLOURS,
        public readonly bool $upperCaseOnly = true,
    ) {
        if ($from !== null && $from !== SpeakerStyle::Prefix) {
            throw new InvalidArgumentException("Speaker labels can only be read from SpeakerStyle::Prefix, got " .
                                               "SpeakerStyle::$from->name.");
        }

        $colours = array_values($colours);
        $valid   = array_filter($colours, fn (mixed $colour): bool =>
            is_string($colour) && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1);
        if ($colours === [] || count($valid) !== count($colours)) {
            throw new InvalidArgumentException("The speaker colours must be a non-empty list of colours such as \"#ffff00\".");
        }
        $this->colours = $colours;
    }
}
