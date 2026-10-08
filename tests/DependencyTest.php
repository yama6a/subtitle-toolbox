<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use GlyphOcr\Recognizer;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class DependencyTest extends TestCase
{
    public function testFindsFunctionsAndClasses(): void
    {
        $this->assertTrue(Dependency::isAvailable("strlen"));
        $this->assertTrue(Dependency::isAvailable(Recognizer::class));
        $this->assertFalse(Dependency::isAvailable("gzuncompress_missing"));
        $this->assertFalse(Dependency::isAvailable("GlyphOcr\\Missing"));
    }


    public function testThrowsTheMessageOfTheCaller(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot decode a PNG - PHP has no ext-zlib.");

        Dependency::check("gzuncompress_missing", "Cannot decode a PNG - PHP has no ext-zlib.");
    }
}
