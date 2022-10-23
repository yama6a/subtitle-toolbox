<?php

namespace SubtitleToolbox\Exceptions;

class InvalidFormatterException extends GenericException
{
    function getErrorCode(): int
    {
        return 101;
    }
}
