<?php

namespace SubtitleToolbox\Exceptions;

class InvalidParserException extends GenericException
{
    function getErrorCode(): int
    {
        return 102;
    }
}
