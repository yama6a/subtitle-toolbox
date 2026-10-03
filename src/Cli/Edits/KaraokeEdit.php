<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Karaoke\WordHighlight;
use SubtitleToolbox\Karaoke\WordHighlightOptions;
use SubtitleToolbox\Subtitle;

final class KaraokeEdit extends Edit
{
    private function __construct(private readonly WordHighlightOptions $options)
    {
    }


    public static function options(): array
    {
        return [
            Option::flag("karaoke", "Write one cue per word timestamp, with the active word styled, for players without karaoke."),
            Option::value("karaoke-style", "TAG", "Style of the active word for --karaoke: b, i, u, s or 'font color=\"#ffff00\"'. Default: u."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "karaoke", ["karaoke-style"]);
        if (!$arguments->has("karaoke")) {
            return null;
        }

        try {
            return new self(new WordHighlightOptions(style: $arguments->value("karaoke-style") ?? "u"));
        } catch (InvalidArgumentException $exception) {
            return Command::fail($exception->getMessage());
        }
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        WordHighlight::apply($subtitle, $this->options);

        return $subtitle;
    }
}
