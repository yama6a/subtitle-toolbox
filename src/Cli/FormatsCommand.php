<?php

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\FormatRegistry;

class FormatsCommand extends Command
{
    public function name(): string
    {
        return "formats";
    }


    public function summary(): string
    {
        return "Lists the format names and file extensions for --from and --to.";
    }


    protected function usageLines(): array
    {
        return [""];
    }


    protected function details(): string
    {
        return "When two formats share an extension, the first one in the list owns it: .sub is MicroDVD and .json\n" .
               "is the JSON of this library. Pass --from to read another one.";
    }


    public function options(): array
    {
        return [];
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        if ($arguments->positionals !== []) {
            self::fail("The formats command takes no arguments.");
        }

        $rows = [["Name", "Extensions", "Read", "Write"]];
        foreach (FormatRegistry::names() as $name) {
            $rows[] = [
                $name,
                implode(" ", array_map(fn (string $extension): string => ".$extension", FormatRegistry::extensions($name))),
                FormatRegistry::parserClass($name) === null ? "no" : "yes",
                FormatRegistry::formatterClass($name) === null ? "no" : "yes",
            ];
        }

        $widths = array_map(fn (int $column): int => max(array_map(fn (array $row): int => strlen($row[$column]), $rows)), [0, 1, 2]);
        foreach ($rows as $row) {
            $console->out(rtrim(str_pad($row[0], $widths[0] + 2) . str_pad($row[1], $widths[1] + 2) .
                                str_pad($row[2], $widths[2] + 2) . $row[3]) . "\n");
        }

        return Application::EXIT_OK;
    }
}
