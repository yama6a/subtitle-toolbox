<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidFormatterException;
use SubtitleToolbox\Exceptions\InvalidParserException;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Parsers\SubtitleParser;


class Subtitle
{
    /** @var array|SubtitleCue[] */
    protected $cues;


    public function __construct()
    {
        $this->cues = [];
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
     * @return array|SubtitleCue[]
     */
    public function getCues(): array
    {
        return $this->cues;
    }


    public function addCue(SubtitleCue $cue, bool $reIndexAfterAdding = true): self
    {
        $this->cues[] = $cue;

        if ($reIndexAfterAdding) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function removeCue(int $cueIndex, bool $reIndexAfterRemoval = true): self
    {
        if (!array_key_exists($cueIndex, $this->cues)) {
            throw new \RuntimeException("Cannot remove cue $cueIndex - cue not found!");
        }

        unset($this->cues[$cueIndex]);

        if ($reIndexAfterRemoval) {
            $this->reIndexCues();
        }

        return $this;
    }


    public function reIndexCues(): self
    {
        usort($this->cues, function (SubtitleCue $cue1, SubtitleCue $cue2) {
            return $cue1->getStart() - $cue2->getStart();
        });

        return $this;
    }


    public function getErrors(): array
    {
        $errors = [];

        if (count($this->cues) === 0) {
            $errors[] = "This subtitle contains no cues!";
        }

        $previousCueEnd   = 0;
        $previousCueIndex = -1;
        foreach ($this->cues as $cueIndex => $cue) {
            if ($cue->getStart() < $previousCueEnd) {
                $errors[] = "The start-time ({$cue->getStart()}) of cue #$cueIndex is " .
                            "before its predecessor's end-time ($previousCueEnd)! " .
                            "Try running reIndexCues() on the subtitle to fix it.";
            }
            $previousCueEnd = $cue->getEnd();

            if ($cueIndex !== ++$previousCueIndex) {
                $errors[] = "The cue-index of cue #$cueIndex is $cueIndex " .
                            "but we expected it to be $previousCueIndex! " .
                            "Try running reIndexCues() on the subtitle to fix it.";
            }

            if ($cue->getStart() > $cue->getEnd()) {
                $errors[] = "The start-time of cue #$cueIndex is after its own end-time!";
            }
        }

        return $errors;
    }

}
