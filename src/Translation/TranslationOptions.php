<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class TranslationOptions
{
    /**
     * @param bool $joinSentences           send the cues of one sentence to the engine as one text
     * @param int  $maxCuesPerSentence      the most cues that one text holds
     * @param int  $maxCharactersPerRequest the most characters that one engine request holds. A longer text goes out alone
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
