<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class SubViewerWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly SubViewerVersion $version = SubViewerVersion::V2,
    ) {
    }
}
