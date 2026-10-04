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
     * Runs the command with the arguments after the command name and returns the exit code.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments, Console $console): int
    {
        return $this->execute(Arguments::parse($arguments, $this->options()), $console);
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


    /**
     * Returns the help of the command. $topic is the word after --help, or null. Only convert reads it.
     */
    public function help(?string $topic = null): string
    {
        return $this->helpHeader() . "\nOptions:\n" . self::optionList([...$this->options(), Option::flag("help", "Show this help.", "h")]);
    }


    protected function helpHeader(): string
    {
        $usage = [];
        foreach ($this->usageLines() as $index => $line) {
            $usage[] = ($index === 0 ? "Usage: " : "       ") . Application::NAME . " " . $this->name() . " $line";
        }

        $header = implode("\n", $usage) . "\n\n" . $this->summary() . "\n";

        return $this->details() === "" ? $header : $header . "\n" . $this->details() . "\n";
    }


    /**
     * @param list<Option> $options
     */
    protected static function optionList(array $options): string
    {
        $width = max(array_map(fn (Option $option): int => strlen($option->synopsis()), $options));
        $list  = "";
        foreach ($options as $option) {
            $list .= "  " . str_pad($option->synopsis(), $width) . "  $option->description\n";
        }

        return $list;
    }
}
