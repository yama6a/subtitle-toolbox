<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    /**
     * @return array<string, array{?string, string}>
     */
    public static function versions(): array
    {
        return [
            "release"        => ["1.40.0", "1.40.0"],
            "v prefix"       => ["v1.40.0", "1.40.0"],
            "box after tag"  => ["1.40.0-2-ge558e33", "1.40.0-2-ge558e33"],
            "branch"         => ["dev-master", "dev"],
            "no version set" => ["1.0.0+no-version-set", "dev"],
            "empty"          => ["", "dev"],
            "unknown"        => [null, "dev"],
        ];
    }


    #[DataProvider("versions")]
    public function testNormalize(?string $version, string $expected): void
    {
        $this->assertSame($expected, Version::normalize($version));
    }


    public function testGetReturnsAReleaseVersionOrDev(): void
    {
        $this->assertMatchesRegularExpression('/^(dev|\d+\.\d+\.\d+\S*)$/', Version::get());
    }
}
