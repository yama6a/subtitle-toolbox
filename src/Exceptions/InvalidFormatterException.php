<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

class InvalidFormatterException extends GenericException
{
    public function getErrorCode(): int
    {
        return 101;
    }
}
