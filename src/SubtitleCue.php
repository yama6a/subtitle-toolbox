<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SubtitleCue
{
    /** @internal The alignment that formatters write for a cue without one: bottom center, 2 on the numpad. */
    public const DEFAULT_ALIGNMENT = 2;

    private float $start = 0;

    private float $end = 0;

    /** @var list<string> */
    private array $lines = [];

    private ?string $identifier = null;

    private ?int $alignment = null;

    private bool $forced = false;

    /** @var array<string, array> */
    private array $formatData = [];

    private static int $timeEdits = 0;


    /**
     * @param string|list<string> $lines
     */
    public function __construct(float $start = 0, float $end = 0, string|array $lines = "")
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
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }


    /**
     * Sets the lines from a list, or from a string with one line per "\n".
     *
     * @param string|list<string> $lines
     */
    public function setLines(string|array $lines): self
    {
        if (is_string($lines)) {
            $lines = explode(LineEnding::Lf->value, $lines);
        }

        $this->lines = [];
        foreach ($lines as $line) {
            $line = StringHelpers::cleanString($line);
            if ($line !== "") {
                $this->lines[] = $line;
            }
        }

        return $this;
    }


    public function getText(): string
    {
        return implode(LineEnding::Lf->value, $this->lines);
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


    /**
     * Moves the start, the end and each word timestamp to $map(seconds). A time below 0 becomes 0.
     *
     * @internal
     * @param callable(float): float $map
     */
    public function mapTimes(callable $map): self
    {
        return $this->setStart(max(0.0, $map($this->getStart())))
                    ->setEnd(max(0.0, $map($this->getEnd())))
                    ->mapWordTimestamps($map);
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
        if ($alignment !== null && !OptionChecks::isAlignment($alignment)) {
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
     * Returns the data under $key, the value of a Format case such as "ass", or an empty array.
     */
    public function findFormatData(string $key): array
    {
        return $this->formatData[$key] ?? [];
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


    /**
     * Stores $data under $key. An empty array removes the key.
     *
     * @throws InvalidArgumentException when a field that a formatter reads has the wrong type, as fromArray() checks it.
     */
    public function setFormatData(string $key, array $data): self
    {
        $this->formatData = FormatDataSchema::withData($this->formatData, $key, $data, true);

        return $this;
    }
}
