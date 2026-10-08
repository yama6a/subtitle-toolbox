<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\AssKaraokeTag;

/**
 * The values that AssFormatter reads during one format() call.
 *
 * @internal
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
