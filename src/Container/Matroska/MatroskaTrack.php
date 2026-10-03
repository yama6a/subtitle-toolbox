<?php

declare(strict_types=1);

namespace SubtitleToolbox\Container\Matroska;

/**
 * MatroskaTrack describes one subtitle track of an MKV file.
 */
final class MatroskaTrack
{
    /**
     * @param string $language the LanguageBCP47 element, else the Language element, else "eng"
     */
    public function __construct(
        public readonly int $number,
        public readonly string $codecId,
        public readonly string $language,
        public readonly ?string $name,
        public readonly bool $default,
        public readonly bool $forced,
    ) {
    }
}
