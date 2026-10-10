<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\AssKaraokeTag;
use SubtitleToolbox\Formatters\Options\AssWriteOptions;

/**
 * @internal
 */
final class AssOutput implements OptionGroup
{
    private function __construct(private readonly AssWriteOptions $options, private readonly string $optionNames)
    {
    }


    public static function group(): string
    {
        return "ass";
    }


    public static function summary(): string
    {
        return "Set how ASS output writes word timestamps and the Default style.";
    }


    /**
     * @return list<Option>
     */
    public static function options(): array
    {
        return [
            Option::value("ass-karaoke-tag", "TAG", "ASS karaoke tag for word timestamps: k, kf or ko. Default: k."),
            Option::value("ass-style", "STYLE", "Change the Default style, for example 'Fontname=Roboto,Fontsize=48,Outline=2'."),
        ];
    }


    public static function needsWordTimestamps(Arguments $arguments): bool
    {
        return $arguments->has("ass-karaoke-tag");
    }


    public static function fromArguments(Arguments $arguments): ?self
    {
        $tag   = $arguments->choice("ass-karaoke-tag", array_column(AssKaraokeTag::cases(), "value"));
        $style = $arguments->value("ass-style");
        if ($tag === null && $style === null) {
            return null;
        }
        if ($tag !== null && $arguments->has("karaoke")) {
            Command::fail("Pass only one of --karaoke and --ass-karaoke-tag.");
        }

        try {
            $options = new AssWriteOptions($tag === null ? AssKaraokeTag::Instant : AssKaraokeTag::from($tag), $style);
        } catch (InvalidArgumentException $exception) {
            Command::fail("The option --ass-style is not valid. " . $exception->getMessage());
        }
        $optionNames = implode(" and ", array_keys(array_filter(["--ass-karaoke-tag" => $tag, "--ass-style" => $style], fn (?string $value): bool => $value !== null)));

        return new self($options, $optionNames);
    }


    /**
     * Returns the passed options of the group, for example "--ass-karaoke-tag and --ass-style".
     */
    public function optionNames(): string
    {
        return $this->optionNames;
    }


    public function formatOptions(Format $outputFormat): AssWriteOptions
    {
        if ($outputFormat !== Format::Ass) {
            Command::fail("Pass --to ass with $this->optionNames.");
        }

        return $this->options;
    }
}
