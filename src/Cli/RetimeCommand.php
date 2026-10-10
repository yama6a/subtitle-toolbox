<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Cli\Edits\RetimeEdit;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class RetimeCommand extends WriteCommand
{
    private ?RetimeEdit $retime = null;


    public function name(): string
    {
        return "retime";
    }


    public function summary(): string
    {
        return "Shift and scale all cue times, or fit them to a video with another frame rate.";
    }


    protected function usageLines(): array
    {
        return ["<input>... [--shift SECONDS] [--scale FACTOR] [--sync OLD=NEW] [--from-fps RATE --to-fps RATE] [options]"];
    }


    protected function details(): string
    {
        return "Pass one or more edits. retime applies them in this order: --sync, --shift, --scale, --from-fps and --to-fps. " .
               "A time that becomes negative becomes 0. --scale 1.001 fixes a subtitle that drifts 3.6 s per hour. " .
               "--from-fps 25 --to-fps 23.976 fits a subtitle for a 25 fps release to a 23.976 fps video. " .
               self::OUTPUT_DETAILS;
    }


    protected function commandOptions(): array
    {
        return RetimeEdit::options();
    }


    protected function prepare(Arguments $arguments): void
    {
        parent::prepare($arguments);

        $this->retime = RetimeEdit::fromArguments($arguments) ?? self::fail("Pass --shift SECONDS, --scale FACTOR, --sync OLD=NEW, or --from-fps RATE and --to-fps RATE.");
    }


    protected function transform(Subtitle $subtitle, Arguments $arguments, Console $console, string $input): Subtitle
    {
        return $this->retime->apply($subtitle, $console, self::label($input));
    }
}
