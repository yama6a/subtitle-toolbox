<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssOptions;

/**
 * The ASS writer settings of convert.
 */
final class AssOutput
{
    private const KARAOKE_TAGS = ["k", "kf", "ko"];


    private function __construct(private readonly string $karaokeTag)
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
        return [Option::value("ass-karaoke-tag", "TAG", "Write word timestamps as ASS karaoke tags \\k, \\kf or \\ko: k, kf or ko. Default: k.")];
    }


    public static function fromArguments(Arguments $arguments): ?self
    {
        $tag = $arguments->value("ass-karaoke-tag");
        if ($tag === null) {
            return null;
        }
        if ($arguments->has("karaoke")) {
            Command::fail("Pass only one of --karaoke and --ass-karaoke-tag.");
        }
        if (!in_array($tag, self::KARAOKE_TAGS, true)) {
            Command::fail("The option --ass-karaoke-tag must be k, kf or ko, got \"$tag\".");
        }

        return new self($tag);
    }


    public function formatOptions(Format $outputFormat): AssOptions
    {
        if ($outputFormat !== Format::Ass) {
            Command::fail("--ass-karaoke-tag needs ASS output.");
        }

        return new AssOptions($this->karaokeTag);
    }
}
