<?php

namespace SubtitleToolbox\Cli;

final class Option
{
    /**
     * Describes one long option. $valueName is null for a flag. A repeatable option collects all its values.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $valueName = null,
        public readonly ?string $short = null,
        public readonly bool $repeatable = false,
    ) {
    }


    public static function flag(string $name, string $description, ?string $short = null): self
    {
        return new self($name, $description, null, $short);
    }


    public static function value(string $name, string $valueName, string $description, ?string $short = null): self
    {
        return new self($name, $description, $valueName, $short);
    }


    public function takesValue(): bool
    {
        return $this->valueName !== null;
    }


    public function synopsis(): string
    {
        $synopsis = ($this->short === null ? "" : "-$this->short, ") . "--$this->name";

        return $this->valueName === null ? $synopsis : "$synopsis $this->valueName";
    }
}
