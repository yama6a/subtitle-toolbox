<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class PodcastTranscriptWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly bool $wordSegments = false,          // writes one segment per word timestamp, for word highlighting
        public readonly bool $prettyPrint = false,           // indents the JSON and ends it with a line break
    ) {
    }
}
