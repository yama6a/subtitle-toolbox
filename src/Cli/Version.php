<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use Composer\InstalledVersions;

/**
 * @internal
 */
final class Version
{
    private const PACKAGE = "yama6a/subtitle-toolbox-php";

    // Box replaces this placeholder with the release version when it builds the PHAR. See .build/build-phar.sh.
    private const BUILD_VERSION = "@package_version@";


    /**
     * Returns the release version, such as "1.40.0", or "dev" outside a release.
     */
    public static function get(): string
    {
        if (self::BUILD_VERSION !== "@" . "package_version@") {
            return self::normalize(self::BUILD_VERSION);
        }
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
            return self::normalize(InstalledVersions::getPrettyVersion(self::PACKAGE));
        }

        return "dev";
    }


    /**
     * Turns "v1.40.0" into "1.40.0", and a branch or an unknown version, such as "dev-master" or "2.x-dev", into "dev".
     */
    public static function normalize(?string $version): string
    {
        if ($version === null || $version === "" || str_starts_with($version, "dev-") || str_ends_with($version, "-dev") || str_contains($version, "no-version-set")) {
            return "dev";
        }

        return preg_replace('/^v(?=\d)/', "", $version);
    }
}
