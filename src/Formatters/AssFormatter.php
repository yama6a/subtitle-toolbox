<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class AssFormatter extends SubtitleFormatter
{
    public const OPTION_KARAOKE_TAG = "karaokeTag";

    // The same header that FFmpeg writes when it converts a text subtitle to ASS.
    private const DEFAULT_SCRIPT_INFO = ["ScriptType" => "v4.00+", "PlayResX" => "384", "PlayResY" => "288", "ScaledBorderAndShadow" => "yes"];

    private const DEFAULT_STYLE = [
        "Default", "Arial", "16", "&H00FFFFFF", "&H00FFFFFF", "&H00000000", "&H00000000",
        "0", "0", "0", "0", "100", "100", "0", "0", "1", "1", "0", "2", "10", "10", "10", "1",
    ];

    private const LEGACY_ALIGNMENTS = [1 => 1, 2 => 2, 3 => 3, 7 => 5, 8 => 6, 9 => 7, 4 => 9, 5 => 10, 6 => 11];

    private const CORE_TIMESTAMP_REGEX = '/^<(\d{2,}):(\d{2}):(\d{2}\.\d{3})>$/';

    private string $karaokeTag = "k";


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $data     = $subtitle->getFormatData(AssParser::FORMAT_DATA_KEY) + $this->defaultData();
        $stripAll = in_array(parent::OPTION_STRIP_ALL_XML_TAGS, $options, true);

        $this->karaokeTag = $options[self::OPTION_KARAOKE_TAG] ?? "k";
        if (!in_array($this->karaokeTag, ["k", "kf", "ko"], true)) {
            throw new InvalidArgumentException("The option " . self::OPTION_KARAOKE_TAG . " must be \"k\", \"kf\" or \"ko\".");
        }

        $order = $data["sectionOrder"];
        if (!in_array("script info", array_map("strtolower", $order), true)) {
            array_unshift($order, "Script Info");
        }
        if (!in_array("events", array_map("strtolower", $order), true)) {
            $order[] = "Events";
        }

        $blocks = [];
        foreach (array_unique($order) as $section) {
            $lines = match (true) {
                strcasecmp($section, "Script Info") === 0                => $this->scriptInfoLines($subtitle, $data),
                strcasecmp($section, $data["stylesSection"] ?? "") === 0 => $this->styleLines($data),
                strcasecmp($section, "Events") === 0                     => $this->eventLines($subtitle, $data, $stripAll),
                default                                                  => $data["sections"][$section] ?? [],
            };
            $blocks[] = implode(StringHelpers::UNIX_LINE_ENDING, ["[$section]", ...$lines]);
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom(
            implode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $blocks) . StringHelpers::UNIX_LINE_ENDING
        ), $options);
    }


    private function defaultData(): array
    {
        return [
            "sectionOrder"       => ["Script Info", "V4+ Styles", "Events"],
            "scriptInfoComments" => [],
            "scriptInfo"         => self::DEFAULT_SCRIPT_INFO,
            "stylesSection"      => "V4+ Styles",
            "styleFormat"        => AssParser::ASS_STYLE_FORMAT,
            "styles"             => [array_combine(AssParser::ASS_STYLE_FORMAT, self::DEFAULT_STYLE)],
            "eventFormat"        => AssParser::ASS_EVENT_FORMAT,
            "commentEvents"      => [],
            "sections"           => [],
        ];
    }


    private function scriptInfoLines(Subtitle $subtitle, array $data): array
    {
        $lines = $data["scriptInfoComments"] ?? [];
        $title = $subtitle->getMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            $lines[] = "Title: " . $this->toSingleLine($title);
        }
        foreach ($data["scriptInfo"] ?? [] as $key => $value) {
            $lines[] = "$key: $value";
        }

        return $lines;
    }


    private function styleLines(array $data): array
    {
        $format = $data["styleFormat"] ?? AssParser::ASS_STYLE_FORMAT;
        $lines  = ["Format: " . implode(", ", $format)];
        foreach ($data["styles"] ?? [] as $style) {
            $lines[] = "Style: " . implode(",", array_map(fn (string $field): string => $style[$field] ?? "", $format));
        }

        return $lines;
    }


    private function eventLines(Subtitle $subtitle, array $data, bool $stripAll): array
    {
        $format        = $data["eventFormat"] ?? AssParser::ASS_EVENT_FORMAT;
        $isSsa         = $this->isSsa($data);
        $commentEvents = $data["commentEvents"] ?? [];
        $comments      = $subtitle->getComments();
        $cues          = array_values($subtitle->getCues());

        $lines = ["Format: " . implode(", ", $format)];
        foreach ([...$cues, null] as $cueIndex => $cue) {
            $commentTime = $cue?->getStart() ?? ($cues === [] ? 0 : end($cues)->getEnd());
            foreach ($comments as $comment) {
                $isAtCue = $cue === null ? $comment["beforeCueIndex"] >= $cueIndex : $comment["beforeCueIndex"] === $cueIndex;
                if ($isAtCue) {
                    $lines[] = $this->commentLine($comment["text"], $format, $commentEvents, $commentTime);
                }
            }

            if ($cue !== null) {
                $lines[] = $this->dialogueLine($cue, $format, $isSsa, $stripAll);
            }
        }

        return $lines;
    }


    /**
     * Writes the stored Comment event with the same text, or a new Comment event at the given time.
     */
    private function commentLine(string $text, array $format, array &$commentEvents, float $time): string
    {
        foreach ($commentEvents as $index => $event) {
            if ($this->fieldValue($event, "Text") === $text) {
                unset($commentEvents[$index]);

                return "Comment: " . implode(",", array_map(fn (string $field): string => $event[$field] ?? "", $format));
            }
        }

        $time   = $this->formatTime($time);
        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start", "end" => $time,
                "text"         => str_replace(StringHelpers::UNIX_LINE_ENDING, "\\N", $text),
                default        => $this->defaultFieldValue($field),
            };
        }

        return "Comment: " . implode(",", $values);
    }


    private function dialogueLine(SubtitleCue $cue, array $format, bool $isSsa, bool $stripAll): string
    {
        $stored = $cue->getFormatData(AssParser::FORMAT_DATA_KEY);
        $fields = $stored["fields"] ?? [];

        $unchanged = !$stripAll && isset($stored["text"]) &&
                     ($stored["lines"] ?? null) === $cue->getLines() &&
                     ($stored["alignment"] ?? null) === $cue->getAlignment();
        [$text, $name] = $unchanged
            ? [$stored["text"], $this->fieldValue($fields, "Name") ?? ""]
            : $this->convertLines($cue, $isSsa, $stripAll);

        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start" => $this->formatTime($cue->getStart()),
                "end"   => $this->formatTime($cue->getEnd()),
                "text"  => $text,
                "name"  => $name,
                default => $fields[$field] ?? $this->defaultFieldValue($field),
            };
        }

        return "Dialogue: " . implode(",", $values);
    }


    /**
     * Converts the core markup of the cue lines to the Text field and returns it together with the speaker name.
     *
     * @return array{string, string}
     */
    private function convertLines(SubtitleCue $cue, bool $isSsa, bool $stripAll): array
    {
        $text = implode(StringHelpers::UNIX_LINE_ENDING, $cue->getLines());
        $name = "";
        if (preg_match('/<v(?:\.[^\s>]*)?\s+([^>]*)>/', $text, $matches)) {
            $name = str_replace(",", "", trim(Markup::decodeEntities($matches[1])));
        }

        $parts = [];
        if ($cue->getAlignment() !== null) {
            $parts[] = ["tag", $isSsa ? "\\a" . self::LEGACY_ALIGNMENTS[$cue->getAlignment()] : "\\an" . $cue->getAlignment()];
        }

        $tokens = $stripAll ? [Markup::stripAllTags($text)] : preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $parts  = [...$parts, ...$this->convertTokens($tokens, $cue)];

        $output = "";
        $block  = [];
        foreach ([...$parts, ["text", ""]] as [$type, $value]) {
            if ($type === "tag") {
                // In one block the last \b, \i, \u, \s or \c wins, so an earlier tag of the same kind has no effect.
                $kind    = preg_match('/^\\\\([biusc])(?![a-z])/', $value, $matches) ? $matches[1] : null;
                $block   = array_filter($block, fn (array $tag): bool => $kind === null || $tag[0] !== $kind);
                $block[] = [$kind, $value];
                continue;
            }

            $output .= ($block === [] ? "" : "{" . implode("", array_column($block, 1)) . "}") . $value;
            $block   = [];
        }

        return [$output, $name];
    }


    /**
     * @param list<string> $tokens text and core markup tags
     *
     * @return list<array{string, string}> override tags and escaped text
     */
    private function convertTokens(array $tokens, SubtitleCue $cue): array
    {
        $karaoke = $this->karaokeDurations($tokens, $cue);
        $parts   = [];
        if ($karaoke["leading"] !== null) {
            $parts[] = ["tag", "\\" . $this->karaokeTag . $karaoke["leading"]];
        }

        $colors         = [];
        $timestampIndex = 0;
        foreach ($tokens as $token) {
            if (!str_starts_with($token, "<")) {
                $parts[] = ["text", $this->escapeText(Markup::decodeEntities($token))];
            } elseif (preg_match('/^<(\/?)([bius])>$/', $token, $matches)) {
                $parts[] = ["tag", "\\" . $matches[2] . ($matches[1] === "" ? "1" : "0")];
            } elseif (preg_match('/^<font\b[^>]*>$/', $token)) {
                $color    = preg_match('/color=["\']?#([0-9a-fA-F]{6})/', $token, $matches) ? strtoupper($matches[1]) : null;
                $colors[] = $color;
                if ($color !== null) {
                    $parts[] = ["tag", $this->colorTag($color)];
                }
            } elseif ($token === "</font>") {
                if (array_pop($colors) !== null) {
                    $outer   = array_values(array_filter($colors));
                    $parts[] = ["tag", $outer === [] ? "\\c" : $this->colorTag(end($outer))];
                }
            } elseif (preg_match(self::CORE_TIMESTAMP_REGEX, $token)) {
                $parts[] = ["tag", "\\" . $this->karaokeTag . $karaoke["durations"][$timestampIndex++]];
            }
        }

        return $parts;
    }


    /**
     * Turns the word timestamps into \k durations in centiseconds. Each syllable lasts until the next one or the cue end.
     *
     * @return array{leading: ?int, durations: list<int>}
     */
    private function karaokeDurations(array $tokens, SubtitleCue $cue): array
    {
        $startCs    = (int) round($cue->getStart() * 100);
        $times      = [];
        $textBefore = false;
        foreach ($tokens as $token) {
            if (preg_match(self::CORE_TIMESTAMP_REGEX, $token, $matches)) {
                $times[] = max($startCs, (int) round(($matches[1] * 3600 + $matches[2] * 60 + (float) $matches[3]) * 100));
            } elseif ($times === [] && !str_starts_with($token, "<") && trim($token) !== "") {
                $textBefore = true;
            }
        }

        if ($times === []) {
            return ["leading" => null, "durations" => []];
        }

        $durations = [];
        $ends      = [...array_slice($times, 1), max($startCs, (int) round($cue->getEnd() * 100))];
        foreach ($times as $index => $time) {
            $durations[] = max(0, $ends[$index] - $time);
        }

        return ["leading" => $textBefore ? $times[0] - $startCs : null, "durations" => $durations];
    }


    private function colorTag(string $rgb): string
    {
        return "\\c&H" . substr($rgb, 4, 2) . substr($rgb, 2, 2) . substr($rgb, 0, 2) . "&";
    }


    private function escapeText(string $text): string
    {
        return str_replace([StringHelpers::UNIX_LINE_ENDING, "\u{00A0}"], ["\\N", "\\h"], $text);
    }


    private function toSingleLine(string $text): string
    {
        return preg_replace('/\s*\n\s*/', " ", trim($text));
    }


    private function fieldValue(array $fields, string $fieldName): ?string
    {
        foreach ($fields as $key => $value) {
            if (strcasecmp($key, $fieldName) === 0) {
                return $value;
            }
        }

        return null;
    }


    private function defaultFieldValue(string $field): string
    {
        return match (strtolower($field)) {
            "layer", "marginl", "marginr", "marginv" => "0",
            "marked"                                 => "Marked=0",
            "style"                                  => "Default",
            default                                  => "",
        };
    }


    private function isSsa(array $data): bool
    {
        $scriptType = array_change_key_case($data["scriptInfo"] ?? [])["scripttype"] ?? "";

        return strcasecmp($scriptType, "v4.00") === 0 || strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    private function formatTime(float $seconds): string
    {
        $centiseconds = (int) round($seconds * 100);

        return sprintf(
            "%d:%02d:%02d.%02d",
            intdiv($centiseconds, 360000),
            intdiv($centiseconds, 6000) % 60,
            intdiv($centiseconds, 100) % 60,
            $centiseconds % 100
        );
    }
}
