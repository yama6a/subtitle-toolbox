<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use SubtitleToolbox\SubtitleCue;

final class CueDifference
{
    public const KIND_ADDED                   = "added";
    public const KIND_REMOVED                 = "removed";
    public const KIND_TEXT_CHANGED            = "text changed";
    public const KIND_TIMING_CHANGED          = "timing changed";
    public const KIND_TEXT_AND_TIMING_CHANGED = "text and timing changed";


    public function __construct(
        private readonly string $kind,
        private readonly ?int $oldIndex,
        private readonly ?int $newIndex,
        private readonly ?SubtitleCue $oldCue,
        private readonly ?SubtitleCue $newCue,
    ) {
    }


    /**
     * Returns one of the KIND_* constants.
     */
    public function getKind(): string
    {
        return $this->kind;
    }


    /**
     * Returns the cue index in the old subtitle, or null for an added cue.
     */
    public function getOldIndex(): ?int
    {
        return $this->oldIndex;
    }


    /**
     * Returns the cue index in the new subtitle, or null for a removed cue.
     */
    public function getNewIndex(): ?int
    {
        return $this->newIndex;
    }


    public function getOldCue(): ?SubtitleCue
    {
        return $this->oldCue;
    }


    public function getNewCue(): ?SubtitleCue
    {
        return $this->newCue;
    }
}
