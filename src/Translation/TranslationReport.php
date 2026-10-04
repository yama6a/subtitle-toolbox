<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

final class TranslationReport
{
    /**
     * @param list<TranslationWarning> $warnings the problems of the translation, ordered by cue index
     */
    public function __construct(
        public readonly array $warnings,
    ) {
    }
}
