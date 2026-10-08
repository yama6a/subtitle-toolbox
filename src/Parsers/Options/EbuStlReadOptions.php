<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

final class EbuStlReadOptions implements FormatReadOptions
{
    /**
     * @param bool $subtractStartOfProgramme Subtract the start-of-programme time code (TCP) from every cue time.
     */
    public function __construct(public readonly bool $subtractStartOfProgramme = false)
    {
    }
}
