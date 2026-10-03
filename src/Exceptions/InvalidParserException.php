<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

class InvalidParserException extends GenericException
{
    public function getErrorCode(): int
    {
        return 102;
    }
}
