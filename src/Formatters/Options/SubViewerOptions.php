<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class SubViewerOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly int $version = 2,                    // SubViewer 1 or 2
    ) {
        if ($version !== 1 && $version !== 2) {
            throw new InvalidArgumentException("The SubViewer version must be 1 or 2, got $version.");
        }
    }
}
