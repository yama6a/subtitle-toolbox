<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

/**
 * The read settings of the chapter formats: YouTube, Podcasting 2.0, FFmetadata and OGM chapters.
 */
final class ChapterReadOptions implements FormatReadOptions
{
    /**
     * @param ?float $mediaDuration Seconds where the last chapter ends. Null ends it at its own start.
     */
    public function __construct(public readonly ?float $mediaDuration = null)
    {
    }
}
