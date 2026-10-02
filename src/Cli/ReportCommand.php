<?php

namespace SubtitleToolbox\Cli;

/**
 * Prints a report on each input file, as text or as JSON.
 */
abstract class ReportCommand extends FileCommand
{
    protected bool $json = false;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    private int $inputCount = 0;


    public function options(): array
    {
        return [
            ...$this->commandOptions(),
            Option::flag("json", "Print JSON: one object for one input file, a list of objects for several."),
            ...$this->inputOptions(),
        ];
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->json    = $arguments->has("json");
        $this->entries = [];
    }


    protected function checkInputs(array $inputs, Arguments $arguments): void
    {
        $this->inputCount = count($inputs);
    }


    /**
     * Prints the text report now, or keeps the JSON entry for the end of the run.
     *
     * @param array<string, mixed> $entry
     */
    protected function emit(Console $console, string $text, array $entry): void
    {
        if ($this->json) {
            $this->entries[] = $entry;
        } else {
            $console->out($text);
        }
    }


    protected function finish(Console $console): void
    {
        if (!$this->json || $this->entries === []) {
            return;
        }

        $data = $this->inputCount === 1 ? $this->entries[0] : $this->entries;
        $console->out(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                         | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
    }


    protected function report(Console $console, string $line): void
    {
        $this->json ? $console->err($line) : $console->out($line);
    }


    protected static function number(int|float|null $value): string
    {
        return match (true) {
            $value === null      => "-",
            is_int($value)       => (string)$value,
            is_infinite($value)  => "INF",
            default              => rtrim(rtrim(number_format($value, 3, ".", ""), "0"), "."),
        };
    }


    /**
     * Returns null for INF and NAN, which JSON cannot hold.
     */
    protected static function jsonNumber(int|float|null $value): int|float|null
    {
        return is_float($value) && !is_finite($value) ? null : $value;
    }
}
