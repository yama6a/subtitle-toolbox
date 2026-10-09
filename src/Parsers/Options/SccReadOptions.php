<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SccReadOptions implements FormatReadOptions
{
    /**
     * @param int $channel 1 reads CC1 and CC3, 2 reads CC2 and CC4.
     * @param SccRollUp $rollUp Screen gives one cue per screen of roll-up captions, Lines gives one cue per row.
     */
    public function __construct(
        public readonly int $channel = 1,
        public readonly SccRollUp $rollUp = SccRollUp::Screen,
    ) {
        if ($channel !== 1 && $channel !== 2) {
            throw new InvalidArgumentException("The SCC data channel must be 1 or 2, got $channel.");
        }
    }
}
