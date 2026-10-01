<?php

namespace SubtitleToolbox;

class Markup
{
    // Word timestamps such as <00:01:02.500> are core markup too, but they are no tag names that keepTags() keeps.
    public const CORE_TAGS = ["b", "i", "u", "s", "font", "v"];


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
}
