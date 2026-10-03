<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class ArgumentsTest extends TestCase
{
    /**
     * @return list<Option>
     */
    private static function spec(): array
    {
        return [
            Option::flag("force", "Overwrite."),
            Option::value("by", "SECONDS", "Seconds."),
            Option::value("output", "PATH", "Output.", "o"),
            new Option("brackets", "Pairs.", "PAIR", null, true),
        ];
    }


    public function testParsesOptionFormsAndPositionals(): void
    {
        $arguments = Arguments::parse(["a.srt", "--by", "-2.5", "-", "--force", "-o", "out.srt", "--brackets={}", "--brackets", "**"], self::spec());

        $this->assertSame(["a.srt", "-"], $arguments->positionals);
        $this->assertTrue($arguments->has("force"));
        $this->assertSame(-2.5, $arguments->float("by"));
        $this->assertSame("out.srt", $arguments->value("output"));
        $this->assertSame(["{}", "**"], $arguments->values("brackets"));
        $this->assertNull($arguments->value("missing"));
        $this->assertSame([], $arguments->values("missing"));
    }


    public function testEqualsSignAndDoubleDash(): void
    {
        $arguments = Arguments::parse(["--by=-1", "--", "--force", "-o"], self::spec());

        $this->assertSame(-1.0, $arguments->float("by"));
        $this->assertSame(["--force", "-o"], $arguments->positionals);
        $this->assertFalse($arguments->has("force"));
    }


    public function testNumberGetters(): void
    {
        $arguments = Arguments::parse(["--by", "3", "--output", "0"], self::spec());

        $this->assertSame(3.0, $arguments->positiveFloat("by"));
        $this->assertSame(3, $arguments->positiveInt("by"));
        $this->assertNull($arguments->positiveInt("missing"));
        $this->assertNull($arguments->positiveFloat("missing"));
    }


    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            "unknown long option"  => [["--nope"], "Unknown option --nope."],
            "unknown short option" => [["-x"], "Unknown option -x."],
            "flag with a value"    => [["--force=yes"], "The option --force takes no value."],
            "missing value"        => [["--by"], "The option --by needs a value."],
            "value given twice"    => [["--by", "1", "--by", "2"], "The option --by is given twice."],
        ];
    }


    #[DataProvider("invalidArguments")]
    public function testInvalidArgumentsFail(array $argv, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse($argv, self::spec());
    }


    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            "not a number"       => ["float", "abc", "The option --by needs a number, got \"abc\"."],
            "zero"               => ["positiveFloat", "0", "The option --by must be greater than 0."],
            "fraction for int"   => ["positiveInt", "1.5", "The option --by needs a whole number greater than 0, got \"1.5\"."],
            "zero for int"       => ["positiveInt", "0", "The option --by needs a whole number greater than 0, got \"0\"."],
        ];
    }


    #[DataProvider("invalidNumbers")]
    public function testInvalidNumbersFail(string $getter, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse(["--by", $value], self::spec())->$getter("by");
    }
}
