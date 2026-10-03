<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

class FpsCommand extends Command
{
    public function name(): string
    {
        return "fps";
    }


    public function aliases(): array
    {
        return ["sync-fps"];
    }


    public function listed(): bool
    {
        return false;
    }


    public function summary(): string
    {
        return "Removed. Use retime --from-fps RATE --to-fps RATE.";
    }


    protected function usageLines(): array
    {
        return ["<input>... --from RATE --to RATE [options]"];
    }


    protected function details(): string
    {
        return "fps exits with code 2 and prints the matching retime call, with --from as --from-fps and --to as --to-fps.";
    }


    public function options(): array
    {
        return [];
    }


    public function run(array $arguments, Console $console): int
    {
        $console->err("fps was removed. Use: " . self::replacementCall("retime", $arguments, ["from" => "from-fps", "to" => "to-fps"]) . "\n");

        return Application::EXIT_USAGE;
    }


    public function execute(Arguments $arguments, Console $console): int
    {
        return $this->run($arguments->positionals, $console);
    }
}
