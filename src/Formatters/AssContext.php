<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\AssKaraokeTag;

/**
 * @internal The values that AssFormatter reads during one format() call.
 */
final readonly class AssContext
{
    public function __construct(
        public bool $isSsa,
        public bool $stripAll,
        public AssKaraokeTag $karaokeTag,
    ) {
    }
}
