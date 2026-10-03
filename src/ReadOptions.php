<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Parsers\FormatReadOptions;

/**
 * The settings of one read. Each parser ignores the fields it does not use.
 */
final class ReadOptions
{
    /**
     * @param ?string            $encoding        The encoding to convert from, such as "Windows-1252". A UTF-16 or UTF-32 BOM wins.
     * @param bool               $lenient         Skip or repair a broken block and record a ParseWarning instead of throwing. SCC, PGS and VobSub ignore it.
     * @param ?float             $fps             The MicroDVD frame rate. It wins over a {1}{1}fps line.
     * @param bool               $wordTimestamps  Write word times as core markup: Whisper, YouTube, Podcasting 2.0 and cloud speech JSON.
     * @param bool               $speakerVoices   Write speakers as voice tags: Whisper and cloud speech JSON.
     * @param float              $lastCueDuration Seconds that a last cue without an end lasts.
     * @param ?int               $track           The VobSub track index.
     * @param ?string            $language        The VobSub language id or the SAMI language class.
     * @param ?FormatReadOptions $format          The settings of one format only.
     */
    public function __construct(
        public readonly ?string $encoding = null,
        public readonly bool $lenient = false,
        public readonly ?float $fps = null,
        public readonly bool $wordTimestamps = false,
        public readonly bool $speakerVoices = false,
        public readonly float $lastCueDuration = 5.0,
        public readonly ?int $track = null,
        public readonly ?string $language = null,
        public readonly ?FormatReadOptions $format = null,
    ) {
        if ($encoding !== null && @iconv($encoding, "UTF-8", "") === false) {
            throw new InvalidArgumentException("The encoding \"$encoding\" is unknown.");
        }
        if ($fps !== null) {
            new FrameRate($fps);
        }
        if (!($lastCueDuration >= 0) || is_infinite($lastCueDuration)) {
            throw new InvalidArgumentException("The last cue duration must be 0 or more seconds, got $lastCueDuration.");
        }
        if ($track !== null && $track < 0) {
            throw new InvalidArgumentException("The track index must be 0 or more, got $track.");
        }
        if ($language !== null && trim($language) === "") {
            throw new InvalidArgumentException("The language must not be empty.");
        }
    }
}
