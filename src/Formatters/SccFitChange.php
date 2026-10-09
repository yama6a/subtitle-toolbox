<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class SccFitChange
{
    /**
     * Records one change that SccWriteOptions::$fit made to the cue at $cueIndex.
     *
     * @internal
     */
    public function __construct(
        public readonly int $cueIndex,
        public readonly SccFitAction $action,
        public readonly string $message,
    ) {
    }
}
