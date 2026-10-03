<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * UnknownFormatException means that format detection found no format that loads without a format argument.
 */
class UnknownFormatException extends InvalidParserException
{
    public function getErrorCode(): int
    {
        return 106;
    }
}
