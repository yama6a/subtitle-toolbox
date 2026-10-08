<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class SccWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?bool $dropFrame True writes 00:01:00;02 and false writes 00:01:00:00. Null takes the parsed mode, else true.
     */
    public function __construct(
        public readonly ?bool $dropFrame = null,
    ) {
    }
}
