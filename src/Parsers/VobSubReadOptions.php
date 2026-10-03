<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The read settings of VobSub. The parser reads the .sub content and takes the .idx content from here.
 */
final class VobSubReadOptions implements FormatReadOptions
{
    /**
     * @param string $idx The content of the .idx file.
     */
    public function __construct(public readonly string $idx)
    {
    }
}
