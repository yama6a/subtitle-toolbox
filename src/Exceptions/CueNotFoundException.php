<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

final class CueNotFoundException extends \RuntimeException implements SubtitleToolboxException
{
    protected const CODE = 105;


    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $previous);
    }
}
