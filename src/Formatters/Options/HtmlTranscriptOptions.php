<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class HtmlTranscriptOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly float $paragraphGap = 2.0,           // seconds of silence that start a new paragraph
    ) {
        if ($paragraphGap < 0) {
            throw new InvalidArgumentException("The paragraph gap must be 0 or more seconds, got $paragraphGap.");
        }
    }
}
