<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Parsers\FormatReadOptions;

/**
 * The format-neutral settings of one read. Each parser ignores the fields it does not use. $format holds the
 * settings of one format.
 */
final class ReadOptions
{
    /**
     * @param ?string            $encoding        The encoding to convert from, such as "Windows-1252". A UTF-16 or UTF-32 BOM wins.
     * @param bool               $lenient         Skip or repair a broken block and record a ParseWarning instead of throwing. SCC, PGS, VobSub and the chapter formats ignore it.
     * @param bool               $wordTimestamps  Write word times as core markup: Whisper, YouTube, Podcasting 2.0 and cloud speech JSON.
     * @param bool               $speakerVoices   Write speakers as voice tags: Whisper and cloud speech JSON.
     * @param float              $lastCueDuration Seconds that a last cue without an end lasts.
     * @param ?FormatReadOptions $format          The settings of one format only.
     */
    public function __construct(
        public readonly ?string $encoding = null,
        public readonly bool $lenient = false,
        public readonly bool $wordTimestamps = false,
        public readonly bool $speakerVoices = false,
        public readonly float $lastCueDuration = 5.0,
        public readonly ?FormatReadOptions $format = null,
    ) {
        if ($encoding !== null && @iconv($encoding, "UTF-8", "") === false) {
            throw new InvalidArgumentException("The encoding \"$encoding\" is unknown.");
        }
        if (!($lastCueDuration >= 0) || is_infinite($lastCueDuration)) {
            throw new InvalidArgumentException("The last cue duration must be 0 or more seconds, got $lastCueDuration.");
        }
    }
}
