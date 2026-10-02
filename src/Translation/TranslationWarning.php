<?php

namespace SubtitleToolbox\Translation;

final class TranslationWarning
{
    /**
     * Holds one problem with the translation of the cue at $cueIndex and what the runner did about it.
     */
    public function __construct(
        public readonly int $cueIndex,
        public readonly string $message,
    ) {
    }
}
