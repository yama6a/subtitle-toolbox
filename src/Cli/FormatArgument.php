<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Format;

/**
 * Reads the format of --from, --from2 and --to: a format name or a file extension.
 *
 * @internal
 */
final class FormatArgument
{
    // The 1.x names of the chapter formats, kept so that 1.x scripts still run.
    private const ALIASES = [
        "ytchapter" => Format::YouTubeChapters,
        "podcast"   => Format::PodcastChapters,
        "ogm"       => Format::OgmChapters,
        "ffmeta"    => Format::FfMetadataChapters,
    ];


    /**
     * Returns the format for a format name or a file extension such as "SRT" or ".ssa".
     */
    public static function find(string $nameOrExtension): Format
    {
        $key = strtolower(ltrim($nameOrExtension, "."));

        return Format::tryFrom($key) ?? self::ALIASES[$key] ?? Format::fromPath("file.$key")
            ?? Command::fail("Unknown format \"$nameOrExtension\". Run \"" . Application::NAME . " formats\" for the list.");
    }


    /**
     * Returns the format as find() does, and fails for a format that the library can write but not read.
     */
    public static function readable(string $nameOrExtension): Format
    {
        $format = self::find($nameOrExtension);
        if (!$format->canRead()) {
            Command::fail("The format $format->value can be written but not read.");
        }

        return $format;
    }
}
