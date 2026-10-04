<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

class SubtitleCue
{
    /** @var float */
    protected $start;

    /** @var float */
    protected $end;

    /** @var array|string[] */
    protected $lines;

    protected ?string $identifier = null;

    protected ?int $alignment = null;

    protected bool $forced = false;

    /** @var array<string, array> */
    protected array $formatData = [];

    private static int $timeEdits = 0;


    public function __construct(float $start = 0, float $end = 0, $lines = "")
    {
        $this->setStart($start);
        $this->setEnd($end);
        $this->setLines($lines);
    }


    /**
     * Returns how often setStart() and setEnd() ran on any cue, so a cache of cue times can tell when it is stale.
     *
     * @internal
     */
    public static function timeEditCount(): int
    {
        return self::$timeEdits;
    }


    public function getStart(): float
    {
        return round($this->start, 3);
    }


    public function setStart(float $start): self
    {
        $this->start = round($start, 3);
        self::$timeEdits++;

        return $this;
    }


    public function getEnd(): float
    {
        return round($this->end, 3);
    }


    public function setEnd(float $end): self
    {
        $this->end = round($end, 3);
        self::$timeEdits++;

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


    /**
     * Replaces the time of each word timestamp in the lines, such as <00:00:02.000>, with $map(seconds).
     * A time below 0 becomes 0.
     *
     * @param callable(float): float $map
     */
    public function mapWordTimestamps(callable $map): self
    {
        $this->lines = array_map(fn (string $line): string => Markup::mapWordTimestamps($line, $map), $this->lines);

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


    public function getAlignment(): ?int
    {
        return $this->alignment;
    }


    /**
     * Sets the position from 1 to 9 in numeric keypad layout, or null for the format default.
     */
    public function setAlignment(?int $alignment): self
    {
        if ($alignment !== null && ($alignment < 1 || $alignment > 9)) {
            throw new InvalidArgumentException("Cannot set alignment $alignment - " .
                                               "the alignment must be a number from 1 to 9!");
        }

        $this->alignment = $alignment;

        return $this;
    }


    public function isForced(): bool
    {
        return $this->forced;
    }


    /**
     * Marks the cue as a forced narrative, which the player shows also when the viewer has turned subtitles off.
     */
    public function setForced(bool $forced): self
    {
        $this->forced = $forced;

        return $this;
    }


    /**
     * Returns the data that only the given format reads, or an empty array.
     */
    public function getFormatData(string $format): array
    {
        return $this->formatData[$format] ?? [];
    }


    /**
     * Returns the format data of all formats, keyed by format.
     *
     * @return array<string, array>
     */
    public function getAllFormatData(): array
    {
        return $this->formatData;
    }


    public function setFormatData(string $format, array $data): self
    {
        if ($data === []) {
            unset($this->formatData[$format]);
        } else {
            $this->formatData[$format] = $data;
        }

        return $this;
    }
}
