<?php

namespace SubtitleToolbox;

class Markup
{
    // Word timestamps such as <00:01:02.500> are core markup too, but they are no tag names that keepTags() keeps.
    public const CORE_TAGS = ["b", "i", "u", "s", "font", "v"];

    /** Matches a core word timestamp such as <00:01:02.500> and captures it as group 1. */
    public const WORD_TIMESTAMP_REGEX = "/(<\d{2,}:[0-5]\d:[0-5]\d\.\d{3}>)/";


    public static function stripAllTags(string $text): string
    {
        return strip_tags($text);
    }


    /**
     * Removes all tags except the given tag names, for example ["b", "i"].
     *
     * @param list<string> $tagNames
     */
    public static function keepTags(string $text, array $tagNames): string
    {
        return strip_tags($text, $tagNames);
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
