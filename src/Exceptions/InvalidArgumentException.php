<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

class InvalidArgumentException extends \InvalidArgumentException implements SubtitleToolboxException
{
    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, $this->getErrorCode(), $previous);
    }


    public function getErrorCode(): int
    {
        return 104;
    }
}
