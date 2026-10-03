<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class TranslationOptions
{
    /**
     * Creates the translation settings: whether cues of one sentence go out as one text, how many cues one text
     * holds at most, and the most characters that one engine request holds.
     */
    public function __construct(
        public readonly bool $joinSentences = true,
        public readonly int $maxCuesPerSentence = 3,
        public readonly int $maxCharactersPerRequest = 5000,
    ) {
        if ($maxCuesPerSentence < 1) {
            throw new InvalidArgumentException("The maximum cues per sentence must be at least 1, got $maxCuesPerSentence.");
        }
        if ($maxCharactersPerRequest < 1) {
            throw new InvalidArgumentException("The maximum characters per request must be at least 1, got $maxCharactersPerRequest.");
        }
    }
}
