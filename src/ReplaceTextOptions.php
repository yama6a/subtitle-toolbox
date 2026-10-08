<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class ReplaceTextOptions
{
    /**
     * @param bool $regex         read the search text as a PCRE pattern with delimiters, such as '/\.{4,}/'
     * @param bool $caseSensitive match the case of the search text
     */
    public function __construct(
        public readonly bool $regex = false,
        public readonly bool $caseSensitive = true,
    ) {
    }
}
