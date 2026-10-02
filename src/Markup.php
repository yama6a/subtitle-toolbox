<?php

namespace SubtitleToolbox;

class Markup
{
    // Word timestamps such as <00:01:02.500> are core markup too, but they are no tag names that keepTags() keeps.
    public const CORE_TAGS = ["b", "i", "u", "s", "font", "v"];

    /** Matches a core word timestamp such as <00:01:02.500> and captures it as group 1. */
    public const WORD_TIMESTAMP_REGEX = "/(<\d{2,}:[0-5]\d:[0-5]\d\.\d{3}>)/";


    // A tag ends at the first ">", even after a lone quote as in <v O'Neil>. strip_tags() would read the quote as
    // the start of an attribute value and remove the text up to the next quote.
    private const TAG_REGEX = '/<(?![ \t\n\r\f\v])[^<>]*>/';


    public static function stripAllTags(string $text): string
    {
        return self::keepTags($text, []);
    }


    /**
     * Removes all tags except the given tag names, for example ["b", "i"].
     *
     * @param list<string> $tagNames
     */
    public static function keepTags(string $text, array $tagNames): string
    {
        $keep = array_map("strtolower", $tagNames);

        return preg_replace_callback(
            self::TAG_REGEX,
            fn (array $tag): string => preg_match('/^<\/?([^\s\/>]+)/', $tag[0], $name) === 1
                && in_array(strtolower($name[1]), $keep, true) ? $tag[0] : "",
            $text
        ) ?? $text;
    }


    /**
     * Decodes HTML entities such as &lt; and &amp; for formats without markup.
     */
    public static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
    }


    /**
     * Escapes &, < and > without htmlspecialchars(), which returns "" for invalid UTF-8 such as Latin-1 text.
     */
    public static function escapeText(string $text): string
    {
        return str_replace(["&", "<", ">"], ["&amp;", "&lt;", "&gt;"], $text);
    }


    /**
     * Removes tags, decodes entities, trims each line and drops the lines that end up empty, for formats without markup.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function plainLines(array $lines): array
    {
        return array_values(array_filter(
            array_map(fn (string $line): string => trim(self::decodeEntities(self::stripAllTags($line))), $lines),
            fn (string $line): bool => $line !== ""
        ));
    }


    /**
     * Counts the characters of the text without tags and entities and without leading and trailing spaces.
     */
    public static function visibleLength(string $text): int
    {
        return self::countCharacters(trim(self::decodeEntities(self::stripAllTags($text))));
    }


    /**
     * Counts UTF-8 characters, or bytes for invalid UTF-8 such as the Latin-1 bytes that MicroDVD keeps.
     */
    public static function countCharacters(string $text): int
    {
        // mbstring is not part of a default PHP build, but PCRE is. preg_match_all() fails on invalid UTF-8.
        return preg_match_all('/./su', $text) ?: strlen($text);
    }


    /**
     * Formats seconds as the body of a core word timestamp, for example 62.5 becomes "00:01:02.500".
     */
    public static function coreTimestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return sprintf(
            "%02d:%02d:%02d.%03d",
            intdiv($milliseconds, 3600000),
            intdiv($milliseconds, 60000) % 60,
            intdiv($milliseconds, 1000) % 60,
            $milliseconds % 1000
        );
    }
}
