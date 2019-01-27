<?php

namespace SubtitleToolbox;

use InvalidArgumentException;

class SubtitleCue
{
    /** @var float */
    protected $start;

    /** @var float */
    protected $end;

    /** @var array|string[] */
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
     * @return array|string[]
     */
    public function getLines(): array
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
        $this->setLinesByArray(explode(StringHelpers::UNIX_LINE_ENDING, $lines));

        return $this;
    }


    public function setLinesByArray(array $lines): self
    {
        $this->lines = [];
        foreach ($lines as $line) {
            $line = StringHelpers::cleanString($line); // remove empty lines and such stuff
            if ($line !== "") {
                $this->lines[] = $line;
            }
        }

        return $this;
    }


    public function getText(): string
    {
        return (count($this->lines) > 0)
            ? implode(StringHelpers::UNIX_LINE_ENDING, $this->lines)
            : "";
    }


    public function addLine(string $line): self
    {
        $line = StringHelpers::cleanString($line);
        if ($line !== '') {
            $this->lines[] = $line;
        }

        return $this;
    }
}
