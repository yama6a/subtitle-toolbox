<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * Class ParsingException
 * Error Code: #100
 *
 * @package SubtitleToolbox\Exceptions
 */
class ParsingException extends GenericException
{
    private ?int $lineNumber;


    public function __construct($message, ?int $lineNumber = null)
    {
        $this->lineNumber = $lineNumber;
        parent::__construct($lineNumber === null ? $message : "$message (line $lineNumber)");
    }


    /**
     * Returns the 1-based input line that caused the error, or null when the parser does not know it.
     */
    public function getLineNumber(): ?int
    {
        return $this->lineNumber;
    }


    public function getErrorCode(): int
    {
        return 100;
    }
}
