<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\AssWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\AssFormatLines;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\Parsers\SsaOverrideTags;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class AssFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = AssWriteOptions::class;

    protected const DEFAULT_BOM = true;

    // The same header that FFmpeg writes when it converts a text subtitle to ASS.
    private const DEFAULT_SCRIPT_INFO = ["ScriptType" => "v4.00+", "PlayResX" => "384", "PlayResY" => "288", "ScaledBorderAndShadow" => "yes"];

    private const DEFAULT_STYLE = [
        "Default", "Arial", "16", "&H00FFFFFF", "&H00FFFFFF", "&H00000000", "&H00000000",
        "0", "0", "0", "0", "100", "100", "0", "0", "1", "1", "0", "2", "10", "10", "10", "1",
    ];

    private const TIME_PATTERN = "%d:%02d:%02d.%02d";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $data    = $subtitle->findFormatData(AssParser::FORMAT_DATA_KEY) + $this->defaultData();
        $context = new AssContext($this->isSsa($data), $options->stripTags, $this->formatOptions($options)->karaokeTag);

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
                strcasecmp($section, "Events") === 0                     => $this->eventLines($subtitle, $data, $context),
                default                                                  => $data["sections"][$section] ?? [],
            };
            $blocks[] = implode(LineEnding::Lf->value, ["[$section]", ...$lines]);
        }

        return $this->applyOutputOptions(
            implode(LineEnding::Lf->value . LineEnding::Lf->value, $blocks) . LineEnding::Lf->value,
            $options
        );
    }


    private function defaultData(): array
    {
        return [
            "sectionOrder"       => ["Script Info", "V4+ Styles", "Events"],
            "scriptInfoComments" => [],
            "scriptInfo"         => self::DEFAULT_SCRIPT_INFO,
            "stylesSection"      => "V4+ Styles",
            "styleFormat"        => AssFormatLines::ASS_STYLE_FORMAT,
            "styles"             => [array_combine(AssFormatLines::ASS_STYLE_FORMAT, self::DEFAULT_STYLE)],
            "eventFormat"        => AssFormatLines::ASS_EVENT_FORMAT,
            "commentEvents"      => [],
            "sections"           => [],
        ];
    }


    private function scriptInfoLines(Subtitle $subtitle, array $data): array
    {
        $lines = $data["scriptInfoComments"] ?? [];
        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            $lines[] = "Title: " . Markup::toSingleLine($title);
        }
        foreach ($data["scriptInfo"] ?? [] as $key => $value) {
            $lines[] = "$key: $value";
        }

        return $lines;
    }


    private function styleLines(array $data): array
    {
        $format = $data["styleFormat"] ?? AssFormatLines::ASS_STYLE_FORMAT;
        $lines  = ["Format: " . implode(", ", $format)];
        foreach ($data["styles"] ?? [] as $style) {
            $lines[] = "Style: " . implode(",", array_map(fn (string $field): string => $style[$field] ?? "", $format));
        }

        return $lines;
    }


    private function eventLines(Subtitle $subtitle, array $data, AssContext $context): array
    {
        $format        = $data["eventFormat"] ?? AssFormatLines::ASS_EVENT_FORMAT;
        $commentEvents = $data["commentEvents"] ?? [];
        $comments      = $subtitle->getComments();
        $cues          = array_values($subtitle->getCues());

        $lines = ["Format: " . implode(", ", $format)];
        foreach ([...$cues, null] as $cueIndex => $cue) {
            $commentTime = $cue?->getStart() ?? ($cues === [] ? 0 : end($cues)->getEnd());
            foreach ($comments as $comment) {
                $isAtCue = $cue === null ? $comment->beforeCueIndex >= $cueIndex : $comment->beforeCueIndex === $cueIndex;
                if ($isAtCue) {
                    $lines[] = $this->commentLine($comment->text, $format, $commentEvents, $commentTime);
                }
            }

            if ($cue !== null) {
                $lines[] = $this->dialogueLine($cue, $format, $context);
            }
        }

        return $lines;
    }


    /**
     * Writes the stored Comment event with the same text, or a new Comment event at the given time.
     */
    private function commentLine(string $text, array $format, array &$commentEvents, float $seconds): string
    {
        foreach ($commentEvents as $index => $event) {
            if ($this->fieldValue($event, "Text") === $text) {
                unset($commentEvents[$index]);

                return "Comment: " . implode(",", array_map(fn (string $field): string => $event[$field] ?? "", $format));
            }
        }

        $time   = sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($seconds));
        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start", "end" => $time,
                "text"         => str_replace(LineEnding::Lf->value, "\\N", $text),
                default        => $this->defaultFieldValue($field),
            };
        }

        return "Comment: " . implode(",", $values);
    }


    private function dialogueLine(SubtitleCue $cue, array $format, AssContext $context): string
    {
        $stored = $cue->findFormatData(AssParser::FORMAT_DATA_KEY);
        $fields = $stored["fields"] ?? [];

        $unchanged = !$context->stripAll && isset($stored["text"]) &&
                     ($stored["lines"] ?? null) === $cue->getLines() &&
                     ($stored["alignment"] ?? null) === $cue->getAlignment();
        [$text, $name] = $unchanged
            ? [$stored["text"], $this->fieldValue($fields, "Name") ?? ""]
            : $this->convertLines($cue, $context);

        $values = [];
        foreach ($format as $field) {
            $values[] = match (strtolower($field)) {
                "start" => sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($cue->getStart())),
                "end"   => sprintf(self::TIME_PATTERN, ...Timecode::centiseconds($cue->getEnd())),
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
    private function convertLines(SubtitleCue $cue, AssContext $context): array
    {
        $text = implode(LineEnding::Lf->value, $cue->getLines());
        $name = str_replace(",", "", Markup::speaker($text) ?? "");

        $parts = [];
        if ($cue->getAlignment() !== null) {
            $parts[] = ["tag", $context->isSsa ? "\\a" . array_flip(SsaOverrideTags::LEGACY_ALIGNMENTS)[$cue->getAlignment()] : "\\an" . $cue->getAlignment()];
        }

        $tokens = $context->stripAll ? [Markup::stripAllTags($text)] : Markup::splitTags($text);
        $parts  = [...$parts, ...$this->convertTokens($tokens, $cue, $context)];

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
     * @param list<string> $tokens text runs at the even indexes and core markup tags at the odd indexes
     *
     * @return list<array{string, string}> override tags and escaped text
     */
    private function convertTokens(array $tokens, SubtitleCue $cue, AssContext $context): array
    {
        $karaoke = $this->karaokeDurations($tokens, $cue);
        $parts   = [];
        if ($karaoke["leading"] !== null) {
            $parts[] = ["tag", "\\" . $context->karaokeTag->value . $karaoke["leading"]];
        }

        $colors         = [];
        $timestampIndex = 0;
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                if ($token !== "") {
                    $parts[] = ["text", $this->escapeText(Markup::decodeEntities($token))];
                }
            } elseif (preg_match('/^<(\/?)([bius])>$/', $token, $matches)) {
                $parts[] = ["tag", "\\" . $matches[2] . ($matches[1] === "" ? "1" : "0")];
            } elseif (preg_match('/^<font\b[^>]*>$/i', $token)) {
                $color    = preg_match('/^#([0-9a-fA-F]{6})/', trim(Markup::fontColor($token) ?? ""), $matches) ? strtoupper($matches[1]) : null;
                $colors[] = $color;
                if ($color !== null) {
                    $parts[] = ["tag", $this->colorTag($color)];
                }
            } elseif (strcasecmp($token, "</font>") === 0) {
                if (array_pop($colors) !== null) {
                    $outer   = array_values(array_filter($colors));
                    $parts[] = ["tag", $outer === [] ? "\\c" : $this->colorTag(end($outer))];
                }
            } elseif (Markup::wordTimestampSeconds($token) !== null) {
                $parts[] = ["tag", "\\" . $context->karaokeTag->value . $karaoke["durations"][$timestampIndex++]];
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
        foreach ($tokens as $index => $token) {
            $seconds = $index % 2 === 1 ? Markup::wordTimestampSeconds($token) : null;
            if ($seconds !== null) {
                $times[] = max($startCs, (int) round($seconds * 100));
            } elseif ($times === [] && $index % 2 === 0 && trim($token) !== "") {
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
        return "\\c&H" . Markup::rgbToBgr($rgb) . "&";
    }


    private function escapeText(string $text): string
    {
        return str_replace([LineEnding::Lf->value, "\u{00A0}"], ["\\N", "\\h"], $text);
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
}
