<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\DialogueDashStyle;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\OptionChecks;

final class ValidationRules
{
    /**
     * The Netflix limits that CueLimits takes as defaults.
     *
     * @internal
     */
    public const NETFLIX_MAX_CHARACTERS_PER_LINE = 42;

    /** @internal */
    public const NETFLIX_MAX_LINES_PER_CUE = 2;

    /** @internal */
    public const NETFLIX_MAX_DURATION = 7;


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
        public readonly ?DialogueDashStyle $dialogueDashStyle = null,
        public readonly ?int $maxSpeakersPerCue = null,
        public readonly ?float $maxWordsPerMinute = null,
        public readonly ?float $minSecondsPerWord = null,
        public readonly ?string $allowedCharacters = null,
        public readonly bool $noAllCapsLines = false,
        public readonly bool $requireCues = false,
        public readonly bool $noUnsortedCues = false,
        public readonly bool $noNegativeDuration = false,
    ) {
        $maximums = ["maxCharactersPerSecond" => $maxCharactersPerSecond, "maxCharactersPerLine" => $maxCharactersPerLine,
                     "maxLinesPerCue" => $maxLinesPerCue, "maxDuration" => $maxDuration,
                     "maxSpeakersPerCue" => $maxSpeakersPerCue, "maxWordsPerMinute" => $maxWordsPerMinute];
        foreach ($maximums as $name => $limit) {
            if ($limit !== null) {
                OptionChecks::notNegative($limit, "The limit $name must be 0 or more, got %s.");
            }
        }
        foreach (["minDuration" => $minDuration, "minGap" => $minGap, "minSecondsPerWord" => $minSecondsPerWord] as $name => $limit) {
            if ($limit !== null) {
                OptionChecks::nonNegativeFinite($limit, "The limit $name must be a finite number of 0 or more, got %s.");
            }
        }

        if ($allowedCharacters !== null && TextChecks::isCharacterClass($allowedCharacters)
            && @preg_match(TextChecks::characterClassPattern($allowedCharacters) . "u", "") === false) {
            throw new InvalidArgumentException("The allowed characters \"$allowedCharacters\" are no valid regular " .
                                               "expression character class.");
        }
    }


    /**
     * Returns the checks of a well-formed cue list: at least one cue, cues in start order, no cue that ends before it
     * starts, and no overlap.
     */
    public static function structure(): self
    {
        return new self(
            requireCues: true,
            noUnsortedCues: true,
            noNegativeDuration: true,
            noOverlap: true,
        );
    }


    /**
     * Returns the limits of the Netflix English (USA) Timed Text Style Guide for adult programs at the given frame rate.
     */
    public static function netflixEnglish(float $frameRate): self
    {
        return new self(
            maxCharactersPerSecond: 20,
            maxCharactersPerLine: self::NETFLIX_MAX_CHARACTERS_PER_LINE,
            maxLinesPerCue: self::NETFLIX_MAX_LINES_PER_CUE,
            minDuration: 5 / 6,
            maxDuration: self::NETFLIX_MAX_DURATION,
            minGap: (new FrameRate($frameRate))->framesToSeconds(2),
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
