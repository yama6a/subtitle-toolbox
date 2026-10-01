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

    protected ?string $identifier = null;


    public function __construct(float $start = 0, float $end = 0, $lines = "")
    {
        $this->setStart($start);
        $this->setEnd($end);
        $this->setLines($lines);
    }


    public function getStart(): float
    {
        return round($this->start, 3);
    }


    public function setStart(float $start): self
    {
        $this->start = round($start, 3);

        return $this;
    }


    public function getEnd(): float
    {
        return round($this->end, 3);
    }


    public function setEnd(float $end): self
    {
        $this->end = round($end, 3);

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
        return match (true) {
            is_array($lines)  => $this->setLinesByArray($lines),
            is_string($lines) => $this->setLinesByString($lines),
            default           => throw new InvalidArgumentException(
                "Can only set cue-text by string or array! " .
                "Tried to set cue-text of cue [{$this->getStart()} >>> {$this->getEnd()}] by " .
                (is_object($lines) ? $lines::class : gettype($lines))),
        };
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
        return implode(StringHelpers::UNIX_LINE_ENDING, $this->lines);
    }


    public function addLine(string $line): self
    {
        $line = StringHelpers::cleanString($line);
        if ($line !== '') {
            $this->lines[] = $line;
        }

        return $this;
    }


    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }


    public function setIdentifier(?string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }
}
