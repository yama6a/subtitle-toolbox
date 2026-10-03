<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class SubtitleTraitsTest extends TestCase
{
    public function testNoTraitCallsAPrivateMethodOfAnotherTrait(): void
    {
        $traits = (new \ReflectionClass(Subtitle::class))->getTraits();
        $owners = [];
        foreach ($traits as $trait) {
            foreach ($trait->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
                $owners[$method->getName()] = $trait->getShortName();
            }
        }

        $calls = [];
        foreach ($traits as $trait) {
            preg_match_all('/(?:\$this->|self::|static::)(\w+)\s*\(/', file_get_contents($trait->getFileName()), $matches);
            foreach (array_unique($matches[1]) as $name) {
                if (isset($owners[$name]) && $owners[$name] !== $trait->getShortName()) {
                    $calls[] = "{$trait->getShortName()} -> $name";
                }
            }
        }

        $this->assertSame([], $calls);
    }
}
