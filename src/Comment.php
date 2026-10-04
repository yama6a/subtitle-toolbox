<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class Comment
{
    /**
     * Holds a comment that a formatter writes before the cue at $beforeCueIndex, or after the last cue when it equals the cue count.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $beforeCueIndex,
    ) {
    }


    /**
     * @internal
     */
    public function withBeforeCueIndex(int $beforeCueIndex): self
    {
        return new self($this->text, $beforeCueIndex);
    }
}
