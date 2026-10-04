<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

final class ParsingException extends GenericException
{
    protected const CODE = 100;


    public function __construct(string $message, private readonly ?int $lineNumber = null, ?\Throwable $previous = null)
    {
        parent::__construct($lineNumber === null ? $message : "$message (line $lineNumber)", $previous);
    }


    /**
     * Returns the 1-based input line that caused the error, or null when the parser does not know it.
     */
    public function getLineNumber(): ?int
    {
        return $this->lineNumber;
    }
}
