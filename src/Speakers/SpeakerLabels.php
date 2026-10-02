<?php

namespace SubtitleToolbox\Speakers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\HearingImpairedOptions;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class SpeakerLabels
{
    /** White, yellow, cyan and green, the speaker colours of the BBC Subtitle Guidelines, in their order of use. */
    public const BBC_COLOURS = ["#ffffff", "#ffff00", "#00ffff", "#00ff00"];

    private const TAG         = '/(<[^<>]*>)/';
    private const VOICE       = '/^<v(\.[^\s>]*)?(?:\s+([^>]*))?>$/';
    private const VOICE_END   = '/^<\/v\s*>$/';
    private const OPEN_STYLE  = '/^<([a-zA-Z][a-zA-Z0-9]*)(?:[\s.][^>]*)?>$/';
    private const CLOSE_STYLE = '/^<\/([a-zA-Z][a-zA-Z0-9]*)\s*>$/';


    /**
     * Returns the number of cues of each speaker, in the order of the first cue of each speaker.
     *
     * @return array<string, int>
     */
    public static function list(Subtitle $subtitle): array
    {
        $counts = [];
        foreach ($subtitle->getCues() as $cue) {
            $speakers = array_unique(array_filter(
                array_column(self::speakerLines($cue->getLines()), 0),
                fn (?string $speaker): bool => $speaker !== null
            ));
            foreach ($speakers as $speaker) {
                $counts[$speaker] = ($counts[$speaker] ?? 0) + 1;
            }
        }

        return $counts;
    }


    /**
     * Renames the speakers of the <v> tags, for example ["SPEAKER_00" => "Anna"]. Other speakers stay.
     *
     * @param array<string, string> $names
     */
    public static function rename(Subtitle $subtitle, array $names): Subtitle
    {
        foreach ($subtitle->getCues() as $cue) {
            $cue->setLinesByArray(array_map(fn (string $line): string => preg_replace_callback(
                '/<v(\.[^\s>]*)?\s+([^>]*)>/',
                function (array $match) use ($names): string {
                    $name = Markup::decodeEntities(trim($match[2]));

                    return isset($names[$name]) ? self::voiceTag((string)$names[$name], $match[1]) : $match[0];
                },
                $line
            ), $cue->getLines()));
        }

        return $subtitle;
    }


    /**
     * Replaces each <v> tag with the speaker name and $separator before the first line of the speaker.
     */
    public static function toPrefix(Subtitle $subtitle, bool $upperCase = true, string $separator = ": "): Subtitle
    {
        return self::convert($subtitle, function (array $lines) use ($upperCase, $separator): array {
            $result = [];
            foreach ($lines as [$speaker, $line, $startsSpeaker]) {
                $name     = $speaker === null ? "" : Markup::escapeText($upperCase ? self::changeCase($speaker, "upper") : $speaker);
                $result[] = $startsSpeaker && $speaker !== null ? $name . Markup::escapeText($separator) . $line : $line;
            }

            return $result;
        });
    }


    /**
     * Replaces the <v> tags with $dash before the first line of each speaker, in cues with two or more speakers.
     */
    public static function toDialogueDashes(Subtitle $subtitle, string $dash = "- "): Subtitle
    {
        return self::convert($subtitle, function (array $lines) use ($dash): array {
            $speakers = count(array_filter(array_column($lines, 2)));
            $result   = [];
            foreach ($lines as [, $line, $startsSpeaker]) {
                $hasDash  = preg_match('/^\h*-/', self::visibleText($line)) === 1;
                $result[] = $speakers >= 2 && $startsSpeaker && !$hasDash ? Markup::escapeText($dash) . $line : $line;
            }

            return $result;
        });
    }


    /**
     * Replaces the <v> tags with a <font color> tag around each line of the speaker. Each speaker gets the next
     * colour in the order of the first cue of each speaker. After the last colour, the list starts again.
     *
     * @param list<string> $colours
     */
    public static function toColours(Subtitle $subtitle, array $colours = self::BBC_COLOURS): Subtitle
    {
        $colours = array_values($colours);
        $valid   = array_filter($colours, fn (mixed $colour): bool =>
            is_string($colour) && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1);
        if ($colours === [] || count($valid) !== count($colours)) {
            throw new InvalidArgumentException("The speaker colours must be a non-empty list of colours such as \"#ffff00\".");
        }

        $assigned = [];
        foreach (array_keys(self::list($subtitle)) as $index => $speaker) {
            $assigned[$speaker] = strtolower($colours[$index % count($colours)]);
        }

        return self::convert($subtitle, fn (array $lines): array => array_map(
            fn (array $line): string => $line[0] === null ? $line[1] : "<font color=\"{$assigned[$line[0]]}\">$line[1]</font>",
            $lines
        ));
    }


    /**
     * Replaces a speaker label such as "JOHN: " at the start of a line, or after its dialogue dash, with <v John>.
     * The label rule is the speakerLabels rule of HearingImpairedOptions.
     */
    public static function fromPrefix(Subtitle $subtitle, bool $upperCaseOnly = true): Subtitle
    {
        $options = new HearingImpairedOptions(
            squareBrackets: false,
            parentheses: false,
            speakerLabelsUpperCaseOnly: $upperCaseOnly,
            musicOnlyLines: false,
        );

        foreach ($subtitle->getCues() as $cue) {
            $lines   = array_values($cue->getLines());
            $result  = [];
            $pending = null;
            foreach ($lines as $index => $line) {
                [$name, $rest] = self::removeLabel($line, $options);
                if ($name === null) {
                    $result[] = ($pending ?? "") . $line;
                    $pending  = null;
                    continue;
                }

                $tag = self::voiceTag($name);
                if (self::visibleText($rest) === "") {
                    $isLast   = $index === count($lines) - 1;
                    $pending  = $isLast ? null : $tag . $rest;
                    $result[] = $isLast ? $line : "";
                    continue;
                }

                $result[] = $tag . $rest;
                $pending  = null;
            }

            if ($result !== $lines) {
                $cue->setLinesByArray($result);
            }
        }

        return $subtitle;
    }


    /**
     * Applies $fn to the result of speakerLines() of each cue that has a <v> tag.
     *
     * @param callable(list<array{?string, string, bool}>): list<string> $fn
     */
    private static function convert(Subtitle $subtitle, callable $fn): Subtitle
    {
        foreach ($subtitle->getCues() as $cue) {
            if (preg_grep('/<\/?v[\s.>]/', $cue->getLines()) !== []) {
                $cue->setLinesByArray($fn(self::speakerLines($cue->getLines())));
            }
        }

        return $subtitle;
    }


    /**
     * Returns each line without <v> tags, with its speaker and whether a new speaker starts on it. A speaker that
     * changes in the middle of a line starts a new line. The style tags that are open there close and open again.
     *
     * @param array<string> $lines
     * @return list<array{?string, string, bool}>
     */
    private static function speakerLines(array $lines): array
    {
        $result  = [];
        $speaker = null;
        $open    = [];
        $split   = false;
        foreach ($lines as $line) {
            $current = "";
            foreach (preg_split(self::TAG, $line, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $token) {
                if ($index % 2 === 0) {
                    $current .= $split && self::visibleText($current) === "" ? ltrim($token) : $token;
                    continue;
                }

                $isVoice = preg_match(self::VOICE, $token, $voice) === 1;
                if (!$isVoice && preg_match(self::VOICE_END, $token) !== 1) {
                    $open     = self::trackStyle($open, $token);
                    $current .= $token;
                    continue;
                }

                $name = $isVoice ? Markup::decodeEntities(trim($voice[2] ?? "")) : "";
                $name = $name === "" ? null : $name;
                if ($name === $speaker) {
                    continue;
                }
                if (self::visibleText($current) !== "") {
                    $closing  = implode("", array_map(fn (array $tag): string => "</$tag[0]>", array_reverse($open)));
                    $result[] = [$speaker, rtrim($current) . $closing];
                    $current  = implode("", array_column($open, 1));
                    $split    = true;
                }
                $speaker = $name;
            }
            $result[] = [$speaker, $current];
            $split    = false;
        }

        $previous = null;
        $seen     = false;
        foreach ($result as $index => [$lineSpeaker, $line]) {
            $hasText          = self::visibleText($line) !== "";
            $result[$index][] = $hasText && (!$seen || $lineSpeaker !== $previous);
            $previous         = $hasText ? $lineSpeaker : $previous;
            $seen             = $seen || $hasText;
        }

        return $result;
    }


    /**
     * @param list<array{string, string}> $open the name and the opening tag of each open style tag
     * @return list<array{string, string}>
     */
    private static function trackStyle(array $open, string $token): array
    {
        if (preg_match(self::OPEN_STYLE, $token, $match) === 1) {
            $open[] = [$match[1], $token];
        } elseif (preg_match(self::CLOSE_STYLE, $token, $match) === 1) {
            for ($index = count($open) - 1; $index >= 0; $index--) {
                if ($open[$index][0] === $match[1]) {
                    array_splice($open, $index, 1);
                    break;
                }
            }
        }

        return $open;
    }


    /**
     * Returns the label name and the line without the dash and the label, or null and the line when it has no label.
     *
     * @return array{?string, string}
     */
    private static function removeLabel(string $line, HearingImpairedOptions $options): array
    {
        $cue = new SubtitleCue(0, 1, $line);
        (new Subtitle())->addCue($cue)->removeHearingImpaired($options);
        $rest = $cue->getText();
        if ($rest === $line) {
            return [null, $line];
        }

        $visible = preg_replace('/^\h*(?:-\h*)?/u', "", self::visibleText($line)) ?? "";
        $name    = trim(explode(":", $visible, 2)[0]);
        if (preg_match('/^\h*-/', self::visibleText($line)) === 1) {
            $rest = preg_replace('/^((?:<[^<>]*>)*)\h*-\h*/', '$1', $rest) ?? $rest;
        }

        return [preg_match('/\p{Ll}/u', $name) === 1 ? $name : self::titleCase($name), $rest];
    }


    /**
     * Turns an upper case label such as "DR. O'NEIL" into "Dr. O'Neil".
     */
    private static function titleCase(string $name): string
    {
        return preg_replace_callback(
            "/(?:^|(?<=[\\s.'-]))\\p{Ll}/u",
            fn (array $match): string => self::changeCase($match[0], "upper"),
            self::changeCase($name, "lower")
        ) ?? $name;
    }


    /**
     * Uses the case rules of Subtitle::changeCase(), which fall back to A to Z without ext-mbstring.
     */
    private static function changeCase(string $text, string $mode): string
    {
        $cue = new SubtitleCue(0, 1, Markup::escapeText($text));
        (new Subtitle())->addCue($cue)->changeCase($mode);

        return Markup::decodeEntities($cue->getText());
    }


    /**
     * Escapes quotes too, because strip_tags() in the formatters reads a quote in a tag as the start of an attribute value.
     */
    private static function voiceTag(string $name, string $class = ""): string
    {
        return "<v$class " . str_replace(["'", "\""], ["&#39;", "&quot;"], Markup::escapeText($name)) . ">";
    }


    private static function visibleText(string $line): string
    {
        return trim(Markup::decodeEntities(Markup::stripAllTags($line)));
    }
}
