<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

abstract class Command
{
    abstract public function name(): string;


    abstract public function summary(): string;


    /**
     * @return list<string> the argument lines after the command name, for the usage text
     */
    abstract protected function usageLines(): array;


    /**
     * @return list<Option>
     */
    abstract public function options(): array;


    abstract public function execute(Arguments $arguments, Console $console): int;


    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [];
    }


    /**
     * Returns the text after the summary in the help of the command, or "".
     */
    protected function details(): string
    {
        return "";
    }


    /**
     * Reports invalid arguments or a file that the command cannot process.
     */
    public static function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }


    public function help(): string
    {
        $usage = [];
        foreach ($this->usageLines() as $index => $line) {
            $usage[] = ($index === 0 ? "Usage: " : "       ") . Application::NAME . " " . $this->name() . " $line";
        }

        $help = implode("\n", $usage) . "\n\n" . $this->summary() . "\n";
        if ($this->details() !== "") {
            $help .= "\n" . $this->details() . "\n";
        }
        if ($this->aliases() !== []) {
            $help .= "\nAliases: " . implode(", ", $this->aliases()) . "\n";
        }

        $options = [...$this->options(), Option::flag("help", "Show this help.", "h")];
        $width   = max(array_map(fn (Option $option): int => strlen($option->synopsis()), $options));
        $help   .= "\nOptions:\n";
        foreach ($options as $option) {
            $help .= "  " . str_pad($option->synopsis(), $width) . "  $option->description\n";
        }

        return $help;
    }
}
