<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
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
     * Reports a file outside the inputs that the command cannot read, or an output that it cannot create. The tool then
     * stops and exits with code 3.
     */
    public static function failFile(string $message): never
    {
        throw new FileFailure($message);
    }


    /**
     * Returns the content of a side file, a file that an option names besides the inputs. Fails with exit code 3 when
     * the file is missing or unreadable.
     */
    public static function readSideFile(string $path): string
    {
        if (!is_file($path)) {
            self::failSideFile($path, "The file does not exist.");
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            self::failSideFile($path, "Cannot read the file.");
        }

        return $content;
    }


    /**
     * Reports a side file that is missing or does not parse. The tool then stops and exits with code 3.
     */
    public static function failSideFile(string $path, string $message): never
    {
        self::failFile("$path: $message");
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
