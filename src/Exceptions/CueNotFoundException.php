<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

class CueNotFoundException extends \RuntimeException implements SubtitleToolboxException
{
    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, $this->getErrorCode(), $previous);
    }


    public function getErrorCode(): int
    {
        return 105;
    }
}
