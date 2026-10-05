<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\FileCommand;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Profanity\MuteRange;
use SubtitleToolbox\Profanity\ProfanityFilter;
use SubtitleToolbox\Profanity\ProfanityMask;
use SubtitleToolbox\Profanity\ProfanityOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

/**
 * @internal
 */
final class MaskingEdit extends Edit
{
    private const MASKS = [
        "stars"        => ProfanityMask::Stars,
        "first-letter" => ProfanityMask::FirstLetter,
        "remove"       => ProfanityMask::Remove,
        "none"         => ProfanityMask::None,
    ];

    /** @var list<MuteRange> */
    private array $muteRanges = [];


    private function __construct(
        private readonly ProfanityOptions $options,
        private readonly ?string $edlPath,
        private readonly ?string $filterPath,
    ) {
    }


    public static function group(): string
    {
        return "masking";
    }


    public static function summary(): string
    {
        return "Mask words and write the mute times for players.";
    }


    public static function options(): array
    {
        return [
            Option::value("mask-words", "FILE", "Mask the words of this file, one per line, as ProfanityFilter does. A * at the end matches any ending."),
            Option::value("mask", "STYLE", "How --mask-words masks a word: stars, first-letter, remove, or none to keep the text. Default: stars."),
            Option::value("mute-edl", "FILE", "Write the times of the --mask-words matches to this EDL file, for Kodi and MPlayer to mute the audio."),
            Option::value("mute-filter", "FILE", "Write an FFmpeg volume filter that mutes the --mask-words matches to this file."),
            Option::value("mute-padding", "SECONDS", "Widen each mute range by this time on both sides. Default: 0."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "mask-words", ["mask", "mute-edl", "mute-filter", "mute-padding"]);
        $words = $arguments->value("mask-words");
        if ($words === null) {
            return null;
        }

        $mask = $arguments->value("mask") ?? "stars";
        if (!isset(self::MASKS[$mask])) {
            Command::fail("Unknown mask \"$mask\". Known masks: " . implode(", ", array_keys(self::MASKS)) . ".");
        }
        foreach (["mute-edl", "mute-filter"] as $option) {
            $path = $arguments->value($option);
            if ($path === FileCommand::DASH) {
                Command::fail("The option --$option needs a file path.");
            }
            if ($path !== null && file_exists($path) && !$arguments->has("force")) {
                Command::fail("$path exists. Pass --force to overwrite it.");
            }
        }
        $padding = $arguments->float("mute-padding") ?? 0.0;
        if ($padding < 0) {
            Command::fail("The option --mute-padding must not be negative.");
        }

        return new self(
            new ProfanityOptions(self::readWordFile($words), self::MASKS[$mask], $padding),
            $arguments->value("mute-edl"),
            $arguments->value("mute-filter"),
        );
    }


    /**
     * Reads one word per line. A UTF-8 BOM, CR LF line endings and empty lines do not count.
     *
     * @return list<string>
     */
    private static function readWordFile(string $path): array
    {
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            Command::fail("Cannot read the word file $path.");
        }

        $lines = preg_split('/\R/', StringHelpers::removeUtf8Bom($content));

        return array_values(array_filter(array_map("trim", $lines), fn (string $line): bool => $line !== ""));
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $this->muteRanges = ProfanityFilter::apply($subtitle, $this->options)->muteRanges;

        return $subtitle;
    }


    /**
     * Returns the files of --mute-edl and --mute-filter, by option name.
     *
     * @return array<string, string>
     */
    public function outputPaths(): array
    {
        return array_filter(["mute-edl" => $this->edlPath, "mute-filter" => $this->filterPath], fn (?string $path): bool => $path !== null);
    }


    /**
     * Writes the mute ranges of the last apply() to the files of --mute-edl and --mute-filter, and returns their paths.
     *
     * @return list<string>
     */
    public function writeMuteFiles(): array
    {
        $written = [];
        foreach ([[$this->edlPath, MuteRange::toEdl(...)], [$this->filterPath, MuteRange::toFfmpegVolumeFilter(...)]] as [$path, $render]) {
            if ($path === null) {
                continue;
            }
            $content = $render($this->muteRanges);
            if (@file_put_contents($path, $content === "" || str_ends_with($content, "\n") ? $content : "$content\n") === false) {
                Command::fail("Cannot write $path.");
            }
            $written[] = $path;
        }

        return $written;
    }
}
