<?php

namespace SubtitleToolbox\Exceptions;

use PHPUnit\Framework\TestCase;

class ParsingExceptionTest extends TestCase
{
    public function testMessageCarriesClassNameAndErrorCodeOnce(): void
    {
        $exception = new ParsingException("broken");

        $this->assertSame("ParsingException (Error #100): broken", $exception->getMessage());
        $this->assertSame(100, $exception->getCode());
    }


    public function testOtherExceptionsCarryTheirShortClassNameAndErrorCode(): void
    {
        $parserException    = new InvalidParserException("broken");
        $formatterException = new InvalidFormatterException("broken");

        $this->assertSame("InvalidParserException (Error #102): broken", $parserException->getMessage());
        $this->assertSame(102, $parserException->getCode());
        $this->assertSame("InvalidFormatterException (Error #101): broken", $formatterException->getMessage());
        $this->assertSame(101, $formatterException->getCode());
    }
}
