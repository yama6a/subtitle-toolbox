<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The examples read files such as movie.srt that the repository lacks, so the test checks syntax and names only.
class DocsCodeTest extends TestCase
{
    // The upgrade guide shows 1.x calls on purpose.
    private const SKIPPED = ["docs/upgrade-2.0.md"];


    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codeBlocks(): iterable
    {
        $root = dirname(__DIR__);
        $files = array_merge(["$root/README.md"], glob("$root/docs/*.md") ?: []);
        foreach ($files as $file) {
            $name = substr($file, strlen($root) + 1);
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }
            preg_match_all('/^```php\n(.*?)^```$/ms', file_get_contents($file), $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$code, $offset]) {
                $line = substr_count(file_get_contents($file), "\n", 0, $offset) + 1;
                yield "$name:$line" => [$name, $code];
            }
        }
    }


    #[DataProvider("codeBlocks")]
    public function testCodeBlockParsesAndNamesExist(string $file, string $code): void
    {
        token_get_all("<?php\n$code", TOKEN_PARSE);
        $this->addToAssertionCount(1);

        preg_match_all('/^use (SubtitleToolbox\\\\[\w\\\\]+)(?: as (\w+))?;/m', $code, $uses, PREG_SET_ORDER);
        $imports = [];
        foreach ($uses as $use) {
            $this->assertTrue(class_exists($use[1]) || interface_exists($use[1]), "$file imports the unknown name $use[1]");
            $imports[$use[2] ?? substr(strrchr($use[1], "\\"), 1)] = $use[1];
        }

        preg_match_all('/\b([A-Z]\w*)::(\w+)\b(\()?/', $code, $references, PREG_SET_ORDER);
        foreach ($references as $reference) {
            [, $short, $member] = $reference;
            if (!isset($imports[$short]) || $member === "class") {
                continue;
            }
            $class = new \ReflectionClass($imports[$short]);
            if (isset($reference[3])) {
                $this->assertTrue($class->hasMethod($member), "$file calls the unknown method $short::$member()");
            } elseif (ctype_upper($member[0])) {
                $this->assertTrue($class->hasConstant($member), "$file uses the unknown constant or case $short::$member");
            }
        }
    }


    public function testDocsShowNoOneXCalls(): void
    {
        $root = dirname(__DIR__);
        $hits = [];
        foreach (array_merge(["$root/README.md"], glob("$root/docs/*.md") ?: []) as $file) {
            $name = substr($file, strlen($root) + 1);
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }
            foreach (file($file) as $number => $line) {
                if (preg_match('/Parser::class|Formatter::class|OPTION_|Subtitle::parse\(|->format\(|detectParser/', $line) === 1) {
                    $hits[] = "$name:" . ($number + 1);
                }
            }
        }

        $this->assertSame([], $hits);
    }
}
