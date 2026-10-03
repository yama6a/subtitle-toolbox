<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * The read settings of SCC files.
 */
final class SccReadOptions implements FormatReadOptions
{
    /**
     * @param int $channel 1 reads CC1 and CC3, 2 reads CC2 and CC4.
     */
    public function __construct(public readonly int $channel = 1)
    {
        if ($channel !== 1 && $channel !== 2) {
            throw new InvalidArgumentException("The SCC data channel must be 1 or 2, got $channel.");
        }
    }
}
