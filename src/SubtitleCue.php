<?php

namespace SubtitleToolbox;

use Doctrine\Common\Collections\ArrayCollection as Collection;
use InvalidArgumentException;

class SubtitleCue
{
    /** @var float */
    protected $start;

    /** @var float */
    protected $end;

    /** @var Collection|string[] */
    protected $lines;


    public function __construct(float $start = 0, float $end = 0, $lines = "")
    {
        $this->setStart($start);
        $this->setEnd($end);
        $this->setLines($lines);
    }


    public function getStart(): float
    {
        return $this->start;
    }


    public function setStart(float $start): self
    {
        $this->start = $start;

        return $this;
    }


    public function getEnd(): float
    {
        return $this->end;
    }


    public function setEnd(float $end): self
    {
        $this->end = $end;

        return $this;
    }


    /**
     * @return Collection|string[]
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }


    public function setLines($lines): self
    {
        switch (true) {
            case is_array($lines):
                return $this->setLinesByArray($lines);
            case is_string($lines):
                return $this->setLinesByString($lines);
            default:
                $type = gettype($lines) === 'object' ? get_class($lines) : gettype($lines);
                throw new InvalidArgumentException(
                    "Can only set cue-text by string or array! " .
                    "Tried to set cue-text of cue [{$this->start} >>> {$this->end}] by $type");
        }
    }


    public function setLinesByString(string $lines): self
    {
        $lines = StringHelpers::cleanString($lines); // remove empty lines and such stuff
        $this->setLinesByArray(explode(StringHelpers::UNIX_LINE_ENDING, $lines));

        return $this;
    }


    public function setLinesByArray(array $lines): self
    {
        $this->lines = new Collection();
        foreach ($lines as $line) {
            $this->addLine($line);
        }

        return $this;
    }


    public function getText(): string
    {
        return ($this->lines and $this->lines->count() > 0)
            ? implode(StringHelpers::UNIX_LINE_ENDING, $this->lines->toArray())
            : "";
    }


    public function addLine(string $line): self
    {
        $line = StringHelpers::cleanString($line);
        if ($line !== '') {
            $this->lines->add($line);
        }

        return $this;
    }
}
