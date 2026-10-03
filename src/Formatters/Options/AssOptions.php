<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class AssOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly string $karaokeTag = "k",        // "k", "kf" or "ko", the override tag of a karaoke syllable
    ) {
        if (!in_array($karaokeTag, ["k", "kf", "ko"], true)) {
            throw new InvalidArgumentException("The karaoke tag must be \"k\", \"kf\" or \"ko\", got \"$karaokeTag\".");
        }
    }
}
