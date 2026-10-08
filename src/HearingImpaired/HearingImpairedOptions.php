<?php

declare(strict_types=1);

namespace SubtitleToolbox\HearingImpaired;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class HearingImpairedOptions
{
    /**
     * @param bool                              $squareBrackets             remove text in square brackets, such as "[DOOR SLAMS]"
     * @param bool                              $parentheses                remove text in parentheses, such as "(laughs)"
     * @param bool                              $speakerLabels              remove a label such as "JOHN:" at the start of a line or after its dash
     * @param bool                              $speakerLabelsUpperCaseOnly remove only labels in upper case with $speakerLabels
     * @param bool                              $musicOnlyLines             remove lines that hold only music notes or a separate "#"
     * @param list<array{0: string, 1: string}> $customBrackets             remove the text between each pair, for example ["{", "}"]
     * @param bool                              $lyrics                     remove text between two music symbols, and lines that start or end with one
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
}
