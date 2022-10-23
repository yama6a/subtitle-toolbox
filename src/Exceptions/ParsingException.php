<?php

namespace SubtitleToolbox\Exceptions;

/**
 * Class ParsingException
 * Error Code: #100
 *
 * @package SubtitleToolbox\Exceptions
 */
class ParsingException extends GenericException
{
    function getErrorCode(): int
    {
        return 100;
    }
}
