<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

/**
 * A command that 2.0 removed. Running it exits with code 2 and prints the call that replaces it.
 */
abstract class RemovedCommand extends Command
{
    /**
     * @param list<string> $arguments the arguments after the command name
     */
    abstract protected function replacement(array $arguments): string;


    public function listed(): bool
    {
        return false;
    }


    public function options(): array
    {
        return [];
    }


    public function run(array $arguments, Console $console): int
    {
        $console->err($this->name() . " was removed. Use: " . $this->replacement($arguments) . "\n");

        return Application::EXIT_USAGE;
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        return $this->run($arguments->positionals, $console);
    }
}
