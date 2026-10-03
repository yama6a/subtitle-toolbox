<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;

final class ValidationRules
{
    /**
     * Creates a rule set. A rule with the limit null or false is off.
     */
    public function __construct(
        public readonly ?float $maxCharactersPerSecond = null,
        public readonly ?int $maxCharactersPerLine = null,
        public readonly ?int $maxLinesPerCue = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $maxDuration = null,
        public readonly ?float $minGap = null,
        public readonly bool $noOverlap = false,
        public readonly bool $noEmptyCues = false,
        public readonly bool $noDoubleSpaces = false,
        public readonly bool $noLeadingOrTrailingSpaces = false,
        public readonly bool $noUnbalancedTags = false,
        public readonly ?string $dialogueDashStyle = null,
        public readonly ?int $maxSpeakersPerCue = null,
        public readonly ?float $maxWordsPerMinute = null,
        public readonly ?float $minSecondsPerWord = null,
        public readonly ?string $allowedCharacters = null,
        public readonly bool $noAllCapsLines = false,
        public readonly bool $requireCues = false,
        public readonly bool $noUnsortedCues = false,
        public readonly bool $noNegativeDuration = false,
        public readonly bool $noIndexGaps = false,
    ) {
        if ($dialogueDashStyle !== null && preg_match("/^[-\x{2010}\x{2013}\x{2014}] ?$/u", $dialogueDashStyle) !== 1) {
            throw new InvalidArgumentException("The dialogue dash style must be a hyphen, an en dash or an em dash, " .
                                               "with or without one space after it, got \"$dialogueDashStyle\".");
        }
        if ($allowedCharacters !== null && TextChecks::isCharacterClass($allowedCharacters)
            && @preg_match(TextChecks::characterClassPattern($allowedCharacters) . "u", "") === false) {
            throw new InvalidArgumentException("The allowed characters \"$allowedCharacters\" are no valid regular " .
                                               "expression character class.");
        }
    }


    /**
     * Returns the checks of a well-formed cue list: at least one cue, cues in start order, no cue that ends before it
     * starts, and cue indexes from 0 without a gap.
     */
    public static function structure(): self
    {
        return new self(
            requireCues: true,
            noUnsortedCues: true,
            noNegativeDuration: true,
            noIndexGaps: true,
            noOverlap: true,
        );
    }


    /**
     * Returns the limits of the Netflix English (USA) Timed Text Style Guide for adult programs at the given frame rate.
     */
    public static function netflixEnglish(float $fps): self
    {
        return new self(
            maxCharactersPerSecond: 20,
            maxCharactersPerLine: 42,
            maxLinesPerCue: 2,
            minDuration: 5 / 6,
            maxDuration: 7,
            minGap: (new FrameRate($fps))->framesToSeconds(2),
            noOverlap: true,
        );
    }


    /**
     * Returns the line length and reading speed limits of the BBC Subtitle Guidelines.
     */
    public static function bbc(): self
    {
        return new self(
            // https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/#Line-length (3.1, broadcast limit)
            maxCharactersPerLine: 37,
            // https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/#Timing (4, upper end of 160 to 180)
            maxWordsPerMinute: 180,
            // https://www.bbc.co.uk/accessibility/forproducts/guides/subtitles/#Target-minimum-timing (4.1)
            minSecondsPerWord: 0.3,
        );
    }
}
