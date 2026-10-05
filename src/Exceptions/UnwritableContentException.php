<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * UnwritableContentException means that the output format cannot hold the subtitle, for example a cue with 5 lines in SCC.
 */
final class UnwritableContentException extends InvalidArgumentException
{
    protected const CODE = 108;
}
