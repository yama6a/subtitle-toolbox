<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\TestCase;

class SubtitleTraitsTest extends TestCase
{
    // Calls that still cross traits. Each entry is "caller trait -> private method".
    private const OPEN_CALLS = [
        "HearingImpairedRemoval -> textTransformsMapCues",
        "Resegmenting -> fixesCuesInStartOrder",
        "Resegmenting -> shortCueMergingSpeakers",
        "ShortCueMerging -> fixesCuesInStartOrder",
        "ShortCueMerging -> joinGroup",
    ];


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
        sort($calls);

        $this->assertSame(self::OPEN_CALLS, $calls);
    }
}
