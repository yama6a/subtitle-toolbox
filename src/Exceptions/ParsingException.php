<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

final class ParsingException extends GenericException
{
    protected const CODE = 100;


    private readonly string $rawMessage;


    public function __construct(string $message, private readonly ?int $lineNumber = null, ?\Throwable $previous = null)
    {
        $this->rawMessage = $message;
        parent::__construct($lineNumber === null ? $message : "$message (line $lineNumber)", $previous);
    }


    /**
     * Returns the message without the class prefix and without the " (line N)" end.
     *
     * @internal
     */
    public function getRawMessage(): string
    {
        return $this->rawMessage;
    }


    /**
     * Returns the 1-based input line that caused the error, or null when the parser does not know it.
     */
    public function getLineNumber(): ?int
    {
        return $this->lineNumber;
    }
}
