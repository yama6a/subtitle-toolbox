<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

/**
 * @internal
 */
final class Arguments
{
    /**
     * @param list<string>                $positionals
     * @param array<string, list<string>> $options     long name => values, an empty list for a flag
     */
    private function __construct(
        public readonly array $positionals,
        private readonly array $options,
    ) {
    }


    /**
     * Parses `--name`, `--name=value`, `--name value`, `-n value` and `--`. A lone `-` is a positional argument.
     *
     * @param list<string> $argv   the arguments after the command name
     * @param list<Option> $spec
     */
    public static function parse(array $argv, array $spec): self
    {
        $byName  = [];
        $byShort = [];
        foreach ($spec as $option) {
            $byName[$option->name] = $option;
            if ($option->short !== null) {
                $byShort[$option->short] = $option;
            }
        }

        $positionals = [];
        $options     = [];
        $count       = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $argument = $argv[$i];
            if ($argument === "--") {
                array_push($positionals, ...array_slice($argv, $i + 1));
                break;
            }
            if ($argument === "-" || !str_starts_with($argument, "-")) {
                $positionals[] = $argument;
                continue;
            }

            $value = null;
            if (str_starts_with($argument, "--")) {
                [$name, $value] = array_pad(explode("=", substr($argument, 2), 2), 2, null);
                $option         = $byName[$name] ?? Command::fail("Unknown option --$name.");
            } else {
                $option = $byShort[substr($argument, 1)] ?? Command::fail("Unknown option $argument.");
            }

            if (!$option->takesValue()) {
                if ($value !== null) {
                    Command::fail("The option --$option->name takes no value.");
                }
                $options[$option->name] = [];
                continue;
            }
            if ($value === null) {
                if ($i + 1 >= $count) {
                    Command::fail("The option --$option->name needs a value.");
                }
                $value = $argv[++$i];
            }
            if (isset($options[$option->name]) && !$option->repeatable) {
                Command::fail("The option --$option->name is given twice.");
            }
            $options[$option->name][] = $value;
        }

        return new self($positionals, $options);
    }


    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }


    public function value(string $name): ?string
    {
        return $this->options[$name][0] ?? null;
    }


    /**
     * @return list<string>
     */
    public function values(string $name): array
    {
        return $this->options[$name] ?? [];
    }


    public function float(string $name): ?float
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            Command::fail("The option --$name needs a number, got \"$value\".");
        }

        return (float)$value;
    }


    public function positiveFloat(string $name): ?float
    {
        $value = $this->float($name);
        if ($value !== null && $value <= 0) {
            Command::fail("The option --$name must be greater than 0.");
        }

        return $value;
    }


    public function positiveInt(string $name): ?int
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value) || (int)$value === 0) {
            Command::fail("The option --$name needs a whole number greater than 0, got \"$value\".");
        }

        return (int)$value;
    }
}
