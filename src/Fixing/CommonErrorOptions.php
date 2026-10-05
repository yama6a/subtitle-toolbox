<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\DialogueDashStyle;

final class CommonErrorOptions
{
    /**
     * Creates the fix settings, see docs/text.md#fixing-common-errors.
     */
    public function __construct(
        public readonly ?string $language = null,
        public readonly bool $doubleSpaces = true,
        public readonly bool $spaceBeforePunctuation = true,
        public readonly bool $missingSpaceAfterPunctuation = true,
        public readonly bool $unbalancedTags = true,
        public readonly bool $emptyTags = true,
        public readonly bool $dialogueDashes = true,
        public readonly DialogueDashStyle $dialogueDashStyle = DialogueDashStyle::HyphenSpace,
        public readonly bool $ellipsis = true,
        public readonly bool $unicodeEllipsis = false,
        public readonly bool $ocrLowercaseL = true,
        public readonly bool $ocrPipe = true,
        public readonly bool $ocrZeroInWords = true,
        public readonly ?OcrReplaceList $replaceList = null,
    ) {
    }
}
