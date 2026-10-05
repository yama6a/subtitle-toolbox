<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * GenericException starts the message with the short class name and the code, for example "ParsingException (Error #100): ".
 * Catch SubtitleToolboxException instead.
 *
 * @internal
 */
abstract class GenericException extends \RuntimeException implements SubtitleToolboxException
{
    protected const CODE = 0;


    public function __construct(string $message, ?\Throwable $previous = null)
    {
        $className = substr(strrchr("\\" . static::class, "\\"), 1);
        parent::__construct("$className (Error #" . static::CODE . "): " . $message, static::CODE, $previous);
    }
}
