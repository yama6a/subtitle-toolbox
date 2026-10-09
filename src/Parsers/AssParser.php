<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class AssParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ass->value;

    // A tag ends at the next backslash, except inside parentheses such as \t(\1c&HFF&).
    private const OVERRIDE_TAG_REGEX = '/\\\\[^\\\\(]*(?<args>\((?:[^()]++|(?&args))*\))?[^\\\\]*/';

    private const EMPTY_FORMAT_DATA = [
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

    /** @var list<SubtitleCue> */
    private array $cues = [];

    /** @var list<array{0: string, 1: int}> */
    private array $comments = [];


    protected function read(string $content): Subtitle
    {
        $this->cues     = [];
        $this->comments = [];

        $subtitle = new Subtitle();
        $data     = self::EMPTY_FORMAT_DATA;

        $section    = null;
        $eventIndex = 0;
        foreach ($this->lines($content) as $lineIndex => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if (preg_match('/^\[([A-Za-z][A-Za-z0-9+ ]*)\]$/', $line, $matches)) {
                $section = $matches[1];
                $this->startSection($data, $section);
                continue;
            }

            if ($section === null) {
                continue;
            }

            match (true) {
                strcasecmp($section, "Script Info") === 0 => $this->readScriptInfoLine($subtitle, $data, $line),
                $this->isStylesSection($section)          => $this->readStyleLine($data, $section, $line),
                strcasecmp($section, "Events") === 0      => $this->readEventLine($data, $line, $lineIndex + 1, $eventIndex),
                default                                   => $data["sections"][$section][] = $line,
            };
        }

        if (!in_array("events", array_map("strtolower", $data["sectionOrder"]), true)) {
            throw new ParsingException("The subtitle has no [Events] section.");
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $data);

        return CommentAnchors::addParsed($subtitle, $this->cues, $this->comments);
    }


    private function startSection(array &$data, string $section): void
    {
        $data["sectionOrder"][] = $section;
        if ($this->isStylesSection($section)) {
            $data["stylesSection"] = $section;
        }
        if (strcasecmp($section, "Events") === 0) {
            $data["eventFormat"] ??= $this->isSsa($data) ? AssFormatLines::SSA_EVENT_FORMAT : AssFormatLines::ASS_EVENT_FORMAT;
        }
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
            $data["styleFormat"] ??= strcasecmp($section, "V4 Styles") === 0 ? AssFormatLines::SSA_STYLE_FORMAT : AssFormatLines::ASS_STYLE_FORMAT;
            $data["styles"][]      = $this->combine($data["styleFormat"], $value, true);
        }
    }


    private function readEventLine(array &$data, string $line, int $lineNumber, int &$eventIndex): void
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
            $this->readEvent($data, $line, $lineNumber, $value, $isComment);
        } catch (ParsingException $exception) {
            $this->fail($exception, $lineNumber, $eventIndex, [$line]);
        }
        $eventIndex++;
    }


    private function readEvent(array &$data, string $line, int $lineNumber, string $value, bool $isComment): void
    {
        $fields = $this->combine($data["eventFormat"], $value, false);
        if ($fields === null) {
            throw new ParsingException("The line \"$line\" has fewer fields than the Format line of the [Events] section.", $lineNumber);
        }

        $start = $this->findField($fields, "Start");
        $end   = $this->findField($fields, "End");
        $text  = $this->findField($fields, "Text");
        if ($start === null || $end === null || $text === null) {
            throw new ParsingException("The Format line of the [Events] section needs the fields Start, End and Text.", $lineNumber);
        }

        if ($isComment) {
            $data["commentEvents"][] = $fields;
            $this->comments[] = [$fields[$text], count($this->cues)];

            return;
        }

        $startTime = $this->secondsFromString($fields[$start], $lineNumber);
        $wrapStyle = array_change_key_case($data["scriptInfo"])["wrapstyle"] ?? "";

        [$lines, $alignment] = $this->convertText($fields[$text], $startTime, $wrapStyle === "2", $lineNumber);

        $name  = $this->findField($fields, "Name");
        $lines = Markup::addSpeaker($lines, $name === null ? "" : $fields[$name]);

        $cue = new SubtitleCue($startTime, $this->secondsFromString($fields[$end], $lineNumber), $lines);
        $cue->setAlignment($alignment);
        $cue->setFormatData(self::FORMAT_DATA_KEY, [
            "fields"    => array_diff_key($fields, array_flip([$start, $end, $text])),
            "text"      => $fields[$text],
            "lines"     => $cue->getLines(),
            "alignment" => $alignment,
        ]);
        $this->cues[] = $cue;
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
            throw new ParsingException("The time \"$time\" is not valid.", $lineNumber);
        }

        return self::boundedTime(Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]), $time, $lineNumber);
    }


    /**
     * Converts the Text field to core markup lines and the alignment of the first \an or \a tag.
     *
     * @return array{list<string>, ?int}
     */
    private function convertText(string $text, float $start, bool $softBreakIsHard, int $lineNumber): array
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
                        Markup::escapeText($part)
                    );
                }
                continue;
            }

            preg_match_all(self::OVERRIDE_TAG_REGEX, substr($part, 1, -1), $tags);
            foreach ($tags[0] as $tag) {
                $tag = trim($tag);
                $tagAlignment = SsaOverrideTags::alignment($tag);
                if ($tagAlignment !== null) {
                    $alignment ??= $tagAlignment;
                } elseif (preg_match('/^\\\\([bius])([01]?)$/', $tag, $matches)) {
                    $markup .= $this->setTag($matches[1], $matches[2] === "1" ? "<$matches[1]>" : null, $openTags);
                } elseif (preg_match('/^\\\\1?c(?:&H([0-9A-Fa-f]{1,8})&?)?$/', $tag, $matches)) {
                    $markup .= $this->setTag("font", isset($matches[1]) ? $this->fontTag($matches[1]) : null, $openTags);
                } elseif (preg_match('/^\\\\(?:k|K|kf|ko)(\d+(?:\.\d+)?)$/', $tag, $matches)) {
                    $markup       .= "<" . Markup::coreTimestamp($karaokeStart) . ">";
                    $karaokeStart  = self::boundedTime($karaokeStart + $matches[1] / 100, $tag, $lineNumber);
                } elseif (preg_match('/^\\\\r/', $tag)) {
                    $markup .= $this->closeAll($openTags);
                } elseif (preg_match('/^\\\\p(\d+)$/', $tag, $matches)) {
                    $drawing = (int) $matches[1] > 0;
                }
            }
        }
        $markup .= $this->closeAll($openTags);

        return [explode("\n", Markup::removeEmptyTagPairs($markup)), $alignment];
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
        return "<font color=\"#" . strtolower(Markup::bgrToRgb(substr(str_pad($hex, 6, "0", STR_PAD_LEFT), -6))) . "\">";
    }
}
