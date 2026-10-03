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
     * Returns false for a command that the overview in the main help leaves out.
     */
    public function listed(): bool
    {
        return true;
    }


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


    /**
     * Returns the call of $command with $arguments, where each option of $renames gets its new name.
     * The words of $added go before the first option.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $renames   old long name => new long name
     * @param list<string>          $added
     */
    protected static function replacementCall(string $command, array $arguments, array $renames, array $added = []): string
    {
        $firstOption = array_key_first(array_filter($arguments, fn (string $argument): bool => $argument !== "-" && str_starts_with($argument, "-")));
        array_splice($arguments, $firstOption ?? count($arguments), 0, $added);

        $words = [Application::NAME, $command];
        foreach ($arguments as $index => $argument) {
            if ($argument === "--") {
                array_push($words, ...array_slice($arguments, $index));
                break;
            }
            [$name, $value] = array_pad(explode("=", $argument, 2), 2, null);
            $renamed        = isset($renames[substr($name, 2)]) && str_starts_with($name, "--") ? "--" . $renames[substr($name, 2)] : $name;
            $words[]        = $value === null ? $renamed : "$renamed=$value";
        }

        return implode(" ", array_map(
            fn (string $word): string => preg_match('~^[\w.,:/=+@%-]+$~', $word) === 1 ? $word : "'" . str_replace("'", "'\\''", $word) . "'",
            $words
        ));
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
