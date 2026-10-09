<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class SccWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?bool $dropFrame True writes 00:01:00;02 and false writes 00:01:00:00. Null takes the parsed mode, else true.
     * @param bool  $fit       Change the text and the timing that SCC cannot hold, in place of throwing. SccFormatter::formatWithReport() lists each change.
     */
    public function __construct(
        public readonly ?bool $dropFrame = null,
        public readonly bool $fit = false,
    ) {
    }
}
