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
}
