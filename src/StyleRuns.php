<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * Writes style runs as core markup. A style run is a text with one style, such as "bold, red".
 *
 * @internal
 */
final class StyleRuns
{
    private const FLAG_TAGS = ["b", "i", "u", "s"];


    /**
     * Writes the runs as core markup and escapes their text. The tags nest in the order font, b, i, u, s. Between two
     * runs, the tags close from the first style that changes and open again. A run of only white space keeps the tags.
     * With $spacesOutside, the white space at both ends of a run goes outside its tags.
     *
     * @param list<array{string, array{color?: ?string, b?: bool, i?: bool, u?: bool, s?: bool}}> $runs the text and
     *        the style of each run, where a null color writes no <font> tag
     */
    public static function toMarkup(array $runs, bool $spacesOutside = false): string
    {
        $markup  = "";
        $open    = [];
        $pending = "";
        foreach ($runs as [$text, $style]) {
            preg_match('/\A(\s*)(.*?)(\s*)\z/s', $text, $parts);
            if ($parts[2] === "") {
                if ($spacesOutside) {
                    $pending .= $text;
                } else {
                    $markup .= $text;
                }
                continue;
            }

            [$before, $body, $after] = $spacesOutside ? [$parts[1], $parts[2], $parts[3]] : ["", $text, ""];
            $wanted = self::tags($style);
            $keep   = 0;
            while ($keep < count($open) && $keep < count($wanted) && $open[$keep] === $wanted[$keep]) {
                $keep++;
            }

            $markup .= self::closeTags(array_slice($open, $keep)) . $pending . $before
                . implode("", array_slice($wanted, $keep)) . Markup::escapeText($body);
            $open    = $wanted;
            $pending = $after;
        }

        return $markup . self::closeTags($open) . $pending;
    }


    /**
     * @param array{color?: ?string, b?: bool, i?: bool, u?: bool, s?: bool} $style
     * @return list<string> the opening tags, outermost first
     */
    private static function tags(array $style): array
    {
        $tags = ($style["color"] ?? null) === null ? [] : ["<font color=\"{$style["color"]}\">"];
        foreach (self::FLAG_TAGS as $tag) {
            if ($style[$tag] ?? false) {
                $tags[] = "<$tag>";
            }
        }

        return $tags;
    }


    /**
     * @param list<string> $openTags opening tags, outermost first
     */
    private static function closeTags(array $openTags): string
    {
        $closing = "";
        foreach (array_reverse($openTags) as $tag) {
            $closing .= "</" . substr(strtok($tag, " >"), 1) . ">";
        }

        return $closing;
    }
}
