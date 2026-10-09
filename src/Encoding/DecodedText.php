<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

/**
 * Holds content in UTF-8, the encoding that StringHelpers::decode() read it from, and a note for a lenient-mode warning.
 *
 * @internal
 */
final class DecodedText
{
    public function __construct(
        public readonly string $content,
        public readonly string $encoding,
        public readonly ?string $warning = null,
    ) {
    }
}
