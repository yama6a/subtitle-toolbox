<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class CommonErrorOptions
{
    /**
     * Creates the fix settings, see the README section "Fixing common errors".
     */
    public function __construct(
        public readonly ?string $language = null,
        public readonly bool $doubleSpaces = true,
        public readonly bool $spaceBeforePunctuation = true,
        public readonly bool $missingSpaceAfterPunctuation = true,
        public readonly bool $unbalancedTags = true,
        public readonly bool $emptyTags = true,
        public readonly bool $dialogueDashes = true,
        public readonly string $dialogueDash = "- ",
        public readonly bool $ellipsis = true,
        public readonly bool $unicodeEllipsis = false,
        public readonly bool $ocrLowercaseL = true,
        public readonly bool $ocrPipe = true,
        public readonly bool $ocrZeroInWords = true,
        public readonly ?OcrReplaceList $replaceList = null,
        public readonly bool $dryRun = false,
    ) {
        if (preg_match("/^[-\x{2010}\x{2013}\x{2014}] ?$/u", $dialogueDash) !== 1) {
            throw new InvalidArgumentException("The dialogue dash must be a hyphen, an en dash or an em dash, " .
                                               "with or without one space after it, got \"$dialogueDash\".");
        }
    }
}
