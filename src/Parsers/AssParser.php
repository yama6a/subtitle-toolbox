<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class AssParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ass->value;

    private const SSA_STYLE_FORMAT = [
        "Name", "Fontname", "Fontsize", "PrimaryColour", "SecondaryColour", "TertiaryColour", "BackColour",
        "Bold", "Italic", "BorderStyle", "Outline", "Shadow", "Alignment", "MarginL", "MarginR", "MarginV",
        "AlphaLevel", "Encoding",
    ];

    // Legacy SSA codes: 1 to 3 are bottom, +4 is top, +8 is middle.
    private const LEGACY_ALIGNMENTS = [1 => 1, 2 => 2, 3 => 3, 5 => 7, 6 => 8, 7 => 9, 9 => 4, 10 => 5, 11 => 6];

    // A tag ends at the next backslash, except inside parentheses such as \t(\1c&HFF&).
    private const OVERRIDE_TAG_REGEX = '/\\\\[^\\\\(]*(?<args>\((?:[^()]++|(?&args))*\))?[^\\\\]*/';

    private int $eventIndex = 0;


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings   = [];
        $this->eventIndex = 0;
        $rawSubtitle      = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle      = StringHelpers::normalizeEOLs($rawSubtitle);

        $subtitle = new Subtitle();
        $data     = [
            "sectionOrder"       => [],
            "scriptInfoComments" => [],
            "scriptInfo"         => [],
            "stylesSection"      => null,
            "styleFormat"        => null,
            "styles"             => [],
            "eventFormat"        => null,
            "commentEvents"      => [],
            "sections"           => [],
        ];

        $section = null;
        foreach (explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle) as $lineIndex => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\[([A-Za-z][A-Za-z0-9+ ]*)\]$/', $line, $matches)) {
                $section                = $matches[1];
                $data["sectionOrder"][] = $section;
                if ($this->isStylesSection($section)) {
                    $data["stylesSection"] = $section;
                }
                continue;
            }

            if ($section === null) {
                continue;
            }

            match (true) {
                strcasecmp($section, "Script Info") === 0 => $this->readScriptInfoLine($subtitle, $data, $line),
                $this->isStylesSection($section)          => $this->readStyleLine($data, $section, $line),
                strcasecmp($section, "Events") === 0      => $this->readEventLine($subtitle, $data, $line, $lineIndex + 1),
                default                                   => $data["sections"][$section][] = $line,
            };
        }

        if (!in_array("events", array_map("strtolower", $data["sectionOrder"]), true)) {
            throw new ParsingException("The subtitle has no [Events] section!");
        }

        $data["eventFormat"] ??= $this->isSsa($data) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $data);

        return $subtitle->reIndexCues();
    }


    private function readScriptInfoLine(Subtitle $subtitle, array &$data, string $line): void
    {
        if (str_starts_with($line, ";")) {
            $data["scriptInfoComments"][] = $line;

            return;
        }

        if (!str_contains($line, ":")) {
            return;
        }

        [$key, $value] = array_map("trim", explode(":", $line, 2));
        if (strcasecmp($key, "Title") === 0) {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $value);
        } else {
            $data["scriptInfo"][$key] = $value;
        }
    }


    private function readStyleLine(array &$data, string $section, string $line): void
    {
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $data["styleFormat"] = array_map("trim", explode(",", $value));
        } elseif (strcasecmp($type, "Style") === 0) {
            $data["styleFormat"] ??= strcasecmp($section, "V4 Styles") === 0 ? self::SSA_STYLE_FORMAT : AssFormatLines::ASS_STYLE_FORMAT;
            $data["styles"][]      = $this->combine($data["styleFormat"], $value, true);
        }
    }


    private function readEventLine(Subtitle $subtitle, array &$data, string $line, int $lineNumber): void
    {
        [$type, $value] = $this->splitDescriptor($line);
        if (strcasecmp($type, "Format") === 0) {
            $data["eventFormat"] = array_map("trim", explode(",", $value));

            return;
        }

        $isComment = strcasecmp($type, "Comment") === 0;
        if (!$isComment && strcasecmp($type, "Dialogue") !== 0) {
            return;
        }

        try {
            $this->readEvent($subtitle, $data, $line, $lineNumber, $value, $isComment);
        } catch (ParsingException $exception) {
            $this->fail($exception, $lineNumber, $this->eventIndex, [$line]);
        }
        $this->eventIndex++;
    }


    private function readEvent(Subtitle $subtitle, array &$data, string $line, int $lineNumber, string $value, bool $isComment): void
    {
        $format = $data["eventFormat"] ?? ($this->isSsa($data) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT);
        $fields = $this->combine($format, $value, false);
        if ($fields === null) {
            throw new ParsingException("Line $lineNumber has fewer fields than the Format line of the [Events] section: $line", $lineNumber);
        }

        $start = $this->findField($fields, "Start");
        $end   = $this->findField($fields, "End");
        $text  = $this->findField($fields, "Text");
        if ($start === null || $end === null || $text === null) {
            throw new ParsingException("The Format line of the [Events] section needs the fields Start, End and Text!", $lineNumber);
        }

        if ($isComment) {
            $data["commentEvents"][] = $fields;
            $subtitle->addComment($fields[$text], count($subtitle->getCues()));

            return;
        }

        $startTime = $this->secondsFromString($fields[$start], $lineNumber);
        $wrapStyle = array_change_key_case($data["scriptInfo"])["wrapstyle"] ?? "";

        [$lines, $alignment] = $this->convertText($fields[$text], $startTime, $wrapStyle === "2");

        $name      = $this->findField($fields, "Name");
        $firstLine = array_key_first(array_filter($lines, fn (string $line): bool => trim($line) !== ""));
        if ($name !== null && $fields[$name] !== "" && $firstLine !== null) {
            $lines[$firstLine] = "<v " . htmlspecialchars($fields[$name], ENT_NOQUOTES, "UTF-8") . ">" . ltrim($lines[$firstLine]);
        }

        $cue = new SubtitleCue($startTime, $this->secondsFromString($fields[$end], $lineNumber), $lines);
        $cue->setAlignment($alignment);
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "fields"    => array_diff_key($fields, array_flip([$start, $end, $text])),
            "text"      => $fields[$text],
            "lines"     => $cue->getLines(),
            "alignment" => $alignment,
        ]);
        $subtitle->addCue($cue, false);
    }


    /**
     * @return array{string, string} the descriptor before the first colon and the value after it
     */
    private function splitDescriptor(string $line): array
    {
        $parts = explode(":", $line, 2);

        return [trim($parts[0]), ltrim($parts[1] ?? "")];
    }


    /**
     * Maps the comma-separated values to the field names. The last field keeps any further commas.
     */
    private function combine(array $format, string $value, bool $padMissing): ?array
    {
        $values = explode(",", $value, count($format));
        if (count($values) < count($format)) {
            if (!$padMissing) {
                return null;
            }
            $values = array_pad($values, count($format), "");
        }

        $fields = [];
        foreach ($format as $index => $fieldName) {
            $fields[$fieldName] = $index === count($format) - 1 ? $values[$index] : trim($values[$index]);
        }

        return $fields;
    }


    private function findField(array $fields, string $fieldName): ?string
    {
        foreach (array_keys($fields) as $key) {
            if (strcasecmp($key, $fieldName) === 0) {
                return $key;
            }
        }

        return null;
    }


    private function isStylesSection(string $section): bool
    {
        return strcasecmp($section, "V4+ Styles") === 0 || strcasecmp($section, "V4 Styles") === 0;
    }


    private function isSsa(array $data): bool
    {
        $scriptType = array_change_key_case($data["scriptInfo"])["scripttype"] ?? "";

        return strcasecmp($scriptType, "v4.00") === 0 || strcasecmp($data["stylesSection"] ?? "", "V4 Styles") === 0;
    }


    private function secondsFromString(string $time, int $lineNumber): float
    {
        if (!preg_match('/^(\d+):(\d{1,2}):(\d{1,2})\.(\d{1,3})$/', trim($time), $matches)) {
            throw new ParsingException("The time of at least one event could not be parsed: $time", $lineNumber);
        }

        return $matches[1] * 3600 + $matches[2] * 60 + $matches[3] + (int) str_pad($matches[4], 3, "0") / 1000;
    }


    /**
     * Converts the Text field to core markup lines and the alignment of the first \an or \a tag.
     *
     * @return array{list<string>, ?int}
     */
    private function convertText(string $text, float $start, bool $softBreakIsHard): array
    {
        $alignment    = null;
        $openTags     = [];
        $drawing      = false;
        $karaokeStart = $start;
        $markup       = "";
        foreach (preg_split('/(\{[^{}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (!str_starts_with($part, "{") || !str_ends_with($part, "}")) {
                if (!$drawing) {
                    $markup .= str_replace(
                        ["\\N", "\\n", "\\h"],
                        ["\n", $softBreakIsHard ? "\n" : " ", "\u{00A0}"],
                        htmlspecialchars($part, ENT_NOQUOTES, "UTF-8")
                    );
                }
                continue;
            }

            preg_match_all(self::OVERRIDE_TAG_REGEX, substr($part, 1, -1), $tags);
            foreach ($tags[0] as $tag) {
                $tag = trim($tag);
                if (preg_match('/^\\\\an([1-9])$/', $tag, $matches)) {
                    $alignment ??= (int) $matches[1];
                } elseif (preg_match('/^\\\\a(\d{1,2})$/', $tag, $matches) && isset(self::LEGACY_ALIGNMENTS[(int) $matches[1]])) {
                    $alignment ??= self::LEGACY_ALIGNMENTS[(int) $matches[1]];
                } elseif (preg_match('/^\\\\([bius])([01]?)$/', $tag, $matches)) {
                    $markup .= $this->setTag($matches[1], $matches[2] === "1" ? "<$matches[1]>" : null, $openTags);
                } elseif (preg_match('/^\\\\1?c(?:&H([0-9A-Fa-f]{1,8})&?)?$/', $tag, $matches)) {
                    $markup .= $this->setTag("font", isset($matches[1]) ? $this->fontTag($matches[1]) : null, $openTags);
                } elseif (preg_match('/^\\\\(?:k|K|kf|ko)(\d+(?:\.\d+)?)$/', $tag, $matches)) {
                    $markup       .= "<" . Markup::coreTimestamp($karaokeStart) . ">";
                    $karaokeStart += $matches[1] / 100;
                } elseif (preg_match('/^\\\\r/', $tag)) {
                    $markup .= $this->closeAll($openTags);
                } elseif (preg_match('/^\\\\p(\d+)$/', $tag, $matches)) {
                    $drawing = (int) $matches[1] > 0;
                }
            }
        }
        $markup .= $this->closeAll($openTags);

        do {
            $markup = preg_replace('/<(b|i|u|s|font)\b[^>]*><\/\1>/', "", $markup, -1, $count);
        } while ($count > 0);

        return [explode("\n", $markup), $alignment];
    }


    /**
     * Opens, replaces or closes one tag. Tags opened after it close first and open again, so tags always nest.
     *
     * @param list<array{string, string}> $openTags tag name and opening tag, innermost last
     */
    private function setTag(string $tagName, ?string $openingTag, array &$openTags): string
    {
        $markup = "";
        $index  = array_search($tagName, array_column($openTags, 0), true);
        if ($index !== false) {
            if ($openTags[$index][1] === $openingTag) {
                return "";
            }

            $inner = array_slice($openTags, $index + 1);
            foreach (array_reverse(array_slice($openTags, $index)) as [$openName]) {
                $markup .= "</$openName>";
            }
            $openTags = array_slice($openTags, 0, $index);
            foreach ($inner as $innerTag) {
                $markup     .= $innerTag[1];
                $openTags[] = $innerTag;
            }
        }

        if ($openingTag !== null) {
            $markup     .= $openingTag;
            $openTags[] = [$tagName, $openingTag];
        }

        return $markup;
    }


    private function closeAll(array &$openTags): string
    {
        $markup = "";
        foreach (array_reverse($openTags) as [$tagName]) {
            $markup .= "</$tagName>";
        }
        $openTags = [];

        return $markup;
    }


    private function fontTag(string $hex): string
    {
        $bgr = substr(str_pad($hex, 6, "0", STR_PAD_LEFT), -6);

        return "<font color=\"#" . strtolower(substr($bgr, 4, 2) . substr($bgr, 2, 2) . substr($bgr, 0, 2)) . "\">";
    }
}
