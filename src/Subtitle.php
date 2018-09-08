<?php

namespace SubtitleToolbox;

use Tightenco\Collect\Support\Collection;

class Subtitle
{
    /** @var Collection|SubtitleCue[] */
    protected $cues;


    public function __construct()
    {
        $this->cues = new Collection();
    }


    /**
     * @return Collection|SubtitleCue[]
     */
    public function getCues(): Collection
    {
        return $this->cues;
    }


    public function addCue(SubtitleCue $cue, bool $reIndexAfterAdding = true): self
    {
        $this->cues->push($cue);

        if ($reIndexAfterAdding) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function removeCue(int $cueIndex, bool $reIndexAfterRemoval = true): self
    {
        if (!$this->cues->has($cueIndex)) {
            throw new \RuntimeException("Cannot remove cue $cueIndex - cue not found!");
        }

        $this->cues->forget($cueIndex);

        if ($reIndexAfterRemoval) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function reIndexCues(): self
    {
        $cues = $this->cues->sortBy(function (SubtitleCue $cue) {
            return $cue->getStart();
        });

        $this->cues = new Collection();

        $i = 0;
        foreach ($cues as $cue) {
            $this->cues->put($i++, $cue);
        }

        return $this;
    }


    public function getErrors(): Collection
    {
        $errors = new Collection();

        if ($this->cues->count() === 0) {
            $errors->push("This subtitle contains no cues!");
        }

        $previousCueEnd   = 0;
        $previousCueIndex = -1;
        foreach ($this->cues as $cueIndex => $cue) {
            if ($cue->getStart() < $previousCueEnd) {
                $errors->push("The start-time of cue #$cueIndex is " .
                              "before its predecessor's end-time!" .
                              "Try running reIndexCues() on the subtitle to fix it.");
            }
            $previousCueEnd = $cue->getEnd();

            if ($cueIndex !== ++$previousCueIndex) {
                $errors->push("The cue-index of cue #$cueIndex is $cueIndex " .
                              "but we expected it to be $previousCueIndex! " .
                              "Try running reIndexCues() on the subtitle to fix it.");
            }

            if ($cue->getStart() > $cue->getEnd()) {
                $errors->Push("The start-time of cue #$cueIndex is after its own end-time!");
            }
        }

        return $errors;
    }
}
