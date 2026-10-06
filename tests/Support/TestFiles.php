<?php

declare(strict_types=1);

namespace SubtitleToolbox\Tests\Support;

use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * Reads the fixture files under tests/files.
 */
final class TestFiles
{
    public const DIR = __DIR__ . "/../files/";


    /**
     * @param string $path the path under tests/files, for example "srt/real/own_styled.srt"
     */
    public static function parse(string $path, Format $format, ReadOptions $options = new ReadOptions()): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::DIR . $path), $format, $options);
    }
}
