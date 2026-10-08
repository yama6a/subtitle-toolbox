<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class PodcastTranscriptWriteOptions implements FormatWriteOptions
{
    /**
     * @param bool $wordSegments Write one segment per word timestamp, for word highlighting.
     * @param bool $prettyPrint  Indent the JSON and ends it with a line break.
     */
    public function __construct(
        public readonly bool $wordSegments = false,
        public readonly bool $prettyPrint = false,
    ) {
    }
}
