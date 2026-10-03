<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Exceptions\SubtitleToolboxException;

class Application
{
    public const NAME = "subtitle-toolbox";

    public const EXIT_OK      = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE   = 2;

    private Console $console;

    /** @var list<Command> */
    private array $commands;


    /**
     * Creates the application with the given streams, or with the standard streams of the process.
     *
     * @param resource|null $stdin
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdin = null, $stdout = null, $stderr = null)
    {
        $this->console  = new Console(
            $stdin ?? fopen("php://stdin", "rb"),
            $stdout ?? fopen("php://stdout", "wb"),
            $stderr ?? fopen("php://stderr", "wb"),
        );
        $this->commands = [
            new ConvertCommand(),
            new ShiftCommand(),
            new ScaleCommand(),
            new FpsCommand(),
            new FixCommand(),
            new StripSdhCommand(),
            new InfoCommand(),
            new ValidateCommand(),
            new SyncCommand(),
            new DiffCommand(),
            new DualCommand(),
            new HlsCommand(),
            new FormatsCommand(),
        ];
    }


    /**
     * Runs the command in $argv, where $argv[0] is the script name, and returns the exit code.
     *
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $name      = $argv[1] ?? null;
        $arguments = array_slice($argv, 2);

        if ($name === null || $name === "--help" || $name === "-h") {
            $this->console->out($this->help());

            return self::EXIT_OK;
        }
        if ($name === "--version" || $name === "-V") {
            $this->console->out(Version::get() . "\n");

            return self::EXIT_OK;
        }
        if ($name === "help") {
            return $this->runHelp($arguments);
        }

        $command = $this->find($name);
        if ($command === null) {
            return $this->usageError("Unknown command \"$name\".", "help");
        }

        if (in_array("--help", $arguments, true) || in_array("-h", $arguments, true)) {
            $this->console->out($command->help());

            return self::EXIT_OK;
        }

        try {
            return $command->execute(Arguments::parse($arguments, $command->options()), $this->console);
        } catch (SubtitleToolboxException $exception) {
            return $this->usageError($exception->getMessage(), "help " . $command->name());
        }
    }


    private function runHelp(array $arguments): int
    {
        if ($arguments === []) {
            $this->console->out($this->help());

            return self::EXIT_OK;
        }

        $command = $this->find($arguments[0]);
        if ($command === null) {
            return $this->usageError("Unknown command \"$arguments[0]\".", "help");
        }
        $this->console->out($command->help());

        return self::EXIT_OK;
    }


    private function find(string $name): ?Command
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $name || in_array($name, $command->aliases(), true)) {
                return $command;
            }
        }

        return null;
    }


    private function usageError(string $message, string $helpArguments): int
    {
        $this->console->err("Error: $message\nRun \"" . self::NAME . " $helpArguments\" for the usage.\n");

        return self::EXIT_USAGE;
    }


    private function help(): string
    {
        $width    = max(array_map(fn (Command $command): int => strlen($command->name()), $this->commands));
        $commands = "";
        foreach ($this->commands as $command) {
            $commands .= "  " . str_pad($command->name(), $width) . "  " . $command->summary() . "\n";
        }
        $commands .= "  " . str_pad("help", $width) . "  Shows the help of a command.\n";

        $name    = self::NAME;
        $version = Version::get();

        return <<<HELP
            $name $version
            Converts, retimes, checks and fixes subtitle files.

            Usage: $name <command> [<file>...] [options]

            Commands:
            $commands
            Run "$name help <command>" or "$name <command> --help" for the options of a command.
            A file argument of - reads standard input. --output - writes standard output.

            Exit codes: 0 success, 1 a file failed or broke a validation rule, 2 invalid arguments.
            Options: -h, --help shows this help, -V, --version prints the version.

            HELP;
    }
}
