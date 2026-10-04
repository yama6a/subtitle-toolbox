<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * The read settings of VobSub. The parser reads the .sub content and takes the .idx content from here.
 */
final class VobSubReadOptions implements FormatReadOptions
{
    /**
     * @param ?string $idx      The content of the .idx file. Subtitle::load() reads the .idx file next to the .sub file when it is null.
     * @param ?int    $track    The track with this "index:" value. Null reads the first track that matches $language.
     * @param ?string $language The track with this "id:" value, such as "de". Null reads the first track that matches $track.
     */
    public function __construct(
        public readonly ?string $idx = null,
        public readonly ?int $track = null,
        public readonly ?string $language = null,
    ) {
        if ($track !== null && $track < 0) {
            throw new InvalidArgumentException("The track index must be 0 or more, got $track.");
        }
        if ($language !== null && trim($language) === "") {
            throw new InvalidArgumentException("The language must not be empty.");
        }
    }
}
