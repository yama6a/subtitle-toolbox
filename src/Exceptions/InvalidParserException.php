<?php

namespace SubtitleToolbox\Exceptions;

class InvalidParserException extends GenericException
{
    public function getErrorCode(): int
    {
        return 102;
    }
}
