<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssKaraokeTag;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;

/**
 * @internal
 */
final class AssOutput implements OptionGroup
{
    private function __construct(private readonly AssKaraokeTag $karaokeTag)
    {
    }


    public static function group(): string
    {
        return "ass";
    }


    public static function summary(): string
    {
        return "Set how ASS output writes word timestamps.";
    }


    /**
     * @return list<Option>
     */
    public static function options(): array
    {
        return [Option::value("ass-karaoke-tag", "TAG", "ASS karaoke tag for word timestamps: k, kf or ko. Default: k.")];
    }


    public static function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("ass-karaoke-tag");
    }


    public static function fromArguments(Arguments $arguments): ?self
    {
        $tag = $arguments->choice("ass-karaoke-tag", array_column(AssKaraokeTag::cases(), "value"));
        if ($tag === null) {
            return null;
        }
        if ($arguments->has("karaoke")) {
            Command::fail("Pass only one of --karaoke and --ass-karaoke-tag.");
        }

        return new self(AssKaraokeTag::from($tag));
    }


    public function formatOptions(Format $outputFormat): AssWriteOptions
    {
        if ($outputFormat !== Format::Ass) {
            Command::fail("Pass --to ass with --ass-karaoke-tag.");
        }

        return new AssWriteOptions($this->karaokeTag);
    }
}
