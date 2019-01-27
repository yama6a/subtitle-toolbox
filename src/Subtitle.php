<?php

namespace SubtitleToolbox;

use Doctrine\Common\Collections\ArrayCollection as Collection;
use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Parsers\SubtitleParser;


class Subtitle
{
    /** @var Collection|SubtitleCue[] */
    protected $cues;


    public function __construct()
    {
        $this->cues = new Collection();
    }


    public static function parse(string $content, string $parserClass): self
    {
        $parser = new $parserClass();

        if (!($parser instanceof SubtitleParser)) {
            throw new InvalidParserException("The supplied parser $parserClass " .
                                             "is not of type " . SubtitleParser::class);
        }

        return $parser->parse($content);
    }


    public function format(string $formatterClass, array $options = []): string
    {
        $formatter = new $formatterClass();

        if (!($formatter instanceof SubtitleFormatter)) {
            throw new InvalidFormatterException("The supplied formatter $formatterClass " .
                                                "is not of type " . SubtitleFormatter::class);
        }

        return $formatter->format($this, $options);
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
        $this->cues->add($cue);

        if ($reIndexAfterAdding) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function removeCue(int $cueIndex, bool $reIndexAfterRemoval = true): self
    {
        if (!$this->cues->containsKey($cueIndex)) {
            throw new \RuntimeException("Cannot remove cue $cueIndex - cue not found!");
        }

        $this->cues->remove($cueIndex);

        if ($reIndexAfterRemoval) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function reIndexCues(): self
    {
        $cues = $this->cues->toArray();
        usort($cues, function (SubtitleCue $cue1, SubtitleCue $cue2) {
            return $cue1->getStart() - $cue2->getStart();
        });

        $this->cues = new Collection($cues);

        return $this;
    }


    public function getErrors(): Collection
    {
        $errors = new Collection();

        if ($this->cues->count() === 0) {
            $errors->add("This subtitle contains no cues!");
        }

        $previousCueEnd   = 0;
        $previousCueIndex = -1;
        foreach ($this->cues as $cueIndex => $cue) {
            if ($cue->getStart() < $previousCueEnd) {
                $errors->add("The start-time ({$cue->getStart()}) of cue #$cueIndex is " .
                             "before its predecessor's end-time ($previousCueEnd)! " .
                             "Try running reIndexCues() on the subtitle to fix it.");
            }
            $previousCueEnd = $cue->getEnd();

            if ($cueIndex !== ++$previousCueIndex) {
                $errors->add("The cue-index of cue #$cueIndex is $cueIndex " .
                             "but we expected it to be $previousCueIndex! " .
                             "Try running reIndexCues() on the subtitle to fix it.");
            }

            if ($cue->getStart() > $cue->getEnd()) {
                $errors->add("The start-time of cue #$cueIndex is after its own end-time!");
            }
        }

        return $errors;
    }

}
