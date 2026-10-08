<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class Comment
{
    /**
     * Holds a comment that a formatter writes before the cue at $beforeCueIndex.
     * A $beforeCueIndex equal to the cue count puts the comment after the last cue.
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
