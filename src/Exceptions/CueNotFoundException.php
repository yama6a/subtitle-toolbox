<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

final class CueNotFoundException extends \RuntimeException implements SubtitleToolboxException
{
    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, 105, $previous);
    }
}
