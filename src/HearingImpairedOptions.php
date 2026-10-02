<?php

namespace SubtitleToolbox;

use InvalidArgumentException;

final class HearingImpairedOptions
{
    /**
     * Creates the removal settings, see the README section "Removing hearing-impaired annotations".
     *
     * @param list<array{0: string, 1: string}> $customBrackets
     */
    public function __construct(
        public readonly bool $squareBrackets = true,
        public readonly bool $parentheses = true,
        public readonly bool $speakerLabels = true,
        public readonly bool $speakerLabelsUpperCaseOnly = true,
        public readonly bool $musicOnlyLines = true,
        public readonly array $customBrackets = [],
        public readonly bool $lyrics = false,
    ) {
        foreach ($customBrackets as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)
                || $pair[0] === "" || $pair[1] === "") {
                throw new InvalidArgumentException("Each custom bracket pair must be a list of two non-empty strings, " .
                                                   "for example [\"{\", \"}\"].");
            }
        }
    }


    /**
     * Returns true when removeHearingImpaired() with these options changes or removes $line.
     */
    public function isHearingImpaired(string $line): bool
    {
        $cue      = new SubtitleCue(0, 1, $line);
        $before   = $cue->getLines();
        $subtitle = (new Subtitle())->addCue($cue)->removeHearingImpaired($this);

        return $subtitle->getCues() === [] || $cue->getLines() !== $before;
    }
}
