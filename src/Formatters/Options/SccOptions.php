<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Formatters\FormatWriteOptions;

final class SccOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?bool $dropFrame = null,             // true writes 00:01:00;02, false 00:01:00:00, null takes the parsed mode, else true
    ) {
    }
}
