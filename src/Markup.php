<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class Markup
{
    /**
     * The core markup tags that style text. <v> names a speaker instead.
     *
     * @internal
     */
    public const STYLE_TAGS = ["b", "i", "u", "s", "font"];

    /**
     * Word timestamps such as <00:01:02.500> are core markup too, but no tag names. keepTags() removes them.
     *
     * @internal
     */
    public const CORE_TAGS = [...self::STYLE_TAGS, "v"];

    /**
     * Matches a tag such as <b> or </font>. A tag has no white space after its "<", so "< b>" is text.
     * A tag ends at the first ">", even after a lone quote as in <v O'Neil>.
     * strip_tags() would read that quote as the start of an attribute value.
     *
     * @internal
     */
    public const TAG = '<(?![ \t\n\r\f\v])[^<>]*>';

    /**
     * Matches a <v> tag with a name, such as <v.loud Anna>. Group 1 holds the classes and group 2 the name.
     *
     * @internal
     */
    public const VOICE_TAG = self::VOICE_TAG_START . '\s+([^>]*)>';

    /**
     * Matches the start of a <v> tag up to the name. Group 1 holds the classes, such as ".loud".
     *
     * @internal
     */
    public const VOICE_TAG_START = '<v(\.[^\s>]*)?';

    /**
     * Matches the body of a core word timestamp, such as 00:01:02.500.
     *
     * @internal
     */
    public const WORD_TIMESTAMP = '\d{2,}:[0-5]\d:[0-5]\d\.\d{3}';

    /**
     * Matches a core word timestamp such as <00:01:02.500> and captures it as group 1.
     *
     * @internal
     */
    public const WORD_TIMESTAMP_REGEX = '/(<' . self::WORD_TIMESTAMP . '>)/';

    /**
     * Matches an entity such as &amp;, &#233; or &#xE9;.
     *
     * @internal
     */
    public const ENTITY = '&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);';

    /**
     * The 8 color classes of WebVTT and their colors, in the order of the default style sheet of the spec.
     *
     * @see https://www.w3.org/TR/webvtt1/#default-text-color
     *
     * @internal
     */
    public const WEBVTT_COLORS = [
        "white"   => "#ffffff",
        "lime"    => "#00ff00",
        "cyan"    => "#00ffff",
        "red"     => "#ff0000",
        "yellow"  => "#ffff00",
        "magenta" => "#ff00ff",
        "blue"    => "#0000ff",
        "black"   => "#000000",
    ];

    private const TAG_REGEX = '/' . self::TAG . '/';


    /**
     * Returns the tag name of a tag body such as "font color=\"yellow\"", in lower case: "font".
     *
     * @internal
     */
    public static function tagName(string $style): string
    {
        return strtolower(preg_split("/\s+/", trim($style))[0]);
    }


    /**
     * Writes ruby as "base (annotation)" for formats without ruby: "<ruby>漢<rt>kan</rt></ruby>" becomes "漢 (kan)".
     * It drops <rp> elements, because the written parentheses take their place.
     *
     * @internal
     */
    public static function rubyAsText(string $text): string
    {
        if (stripos($text, "<ruby") === false) {
            return $text;
        }

        return preg_replace_callback('/<ruby\b[^>]*>(.*?)(?:<\/ruby\s*>|$)/is', function (array $ruby): string {
            $parts = preg_split('/<rt\b[^>]*>/i', preg_replace('/<rp\b[^>]*>.*?<\/rp\s*>/is', "", $ruby[1]));
            $text  = array_shift($parts);
            foreach ($parts as $part) {
                [$annotation, $base] = array_pad(preg_split('/<\/rt\s*>/i', $part, 2), 2, "");
                $text = trim($annotation) === "" ? $text . $base : rtrim($text) . " (" . trim($annotation) . ")" . $base;
            }

            return $text;
        }, $text) ?? $text;
    }


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
     * Returns a <v> tag for the speaker name with &, < and > escaped and quotes kept.
     * $class holds voice classes such as ".loud".
     */
    public static function voiceTag(string $name, string $class = ""): string
    {
        return "<v$class " . self::escapeText($name) . ">";
    }


    /**
     * Escapes $text and writes a core word timestamp before each word, searching from the end of the previous word.
     * A word that is empty, has no start or is not in the rest of the text gets no timestamp.
     *
     * @param list<array{string, ?float}> $words the text and the start in seconds of each word
     */
    public static function insertWordTimestamps(string $text, array $words): string
    {
        $markup   = "";
        $copied   = 0;
        $searchAt = 0;
        foreach ($words as [$word, $start]) {
            $position = $word === "" || $start === null ? false : strpos($text, $word, $searchAt);
            if ($position === false) {
                continue;
            }

            $markup  .= self::escapeText(substr($text, $copied, $position - $copied)) . "<" . self::coreTimestamp($start) . ">";
            $copied   = $position;
            $searchAt = $position + strlen($word);
        }

        return $markup . self::escapeText(substr($text, $copied));
    }


    /**
     * Decodes &lt;, &gt; and &amp;, the entities that escapeText() writes, and keeps all other entities.
     *
     * @internal
     */
    public static function unescapeText(string $text): string
    {
        return strtr($text, ["&lt;" => "<", "&gt;" => ">", "&amp;" => "&"]);
    }


    /**
     * Escapes a changed text run as escapeText() does.
     * It keeps & and > unescaped where the raw form $raw has them unescaped, as WebVTT text does.
     * An & before an entity name gets escaped.
     *
     * @internal
     */
    public static function escapeTextLike(string $text, string $raw): string
    {
        $entity = '(?=' . self::ENTITY . ')&';
        $text   = preg_match("/&(?!lt;|gt;|amp;)/", $raw) === 1
            ? (preg_replace("/$entity/", "&amp;", $text) ?? str_replace("&", "&amp;", $text))
            : str_replace("&", "&amp;", $text);
        $text   = str_replace("<", "&lt;", $text);

        return str_contains($raw, ">") ? $text : str_replace(">", "&gt;", $text);
    }


    /**
     * Splits a line at its tags. The text runs are at the even indexes and the tags at the odd indexes.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function splitTags(string $line): array
    {
        return preg_split('/(' . self::TAG . ')/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    }


    /**
     * Calls $fn for each text run between tags, with &lt;, &gt; and &amp; decoded.
     * $first and $last mark the first and the last run of the line that holds text.
     *
     * @param list<string>                                            $lines
     * @param callable(string $text, bool $first, bool $last): string $fn
     * @return list<string>
     */
    public static function mapTextRuns(array $lines, callable $fn): array
    {
        foreach ($lines as $lineIndex => $line) {
            $tokens = self::splitTags($line);
            $runs   = array_keys(array_filter($tokens, fn (string $token, int $index): bool =>
                $index % 2 === 0 && $token !== "", ARRAY_FILTER_USE_BOTH));
            foreach ($runs as $position => $index) {
                $text   = self::unescapeText($tokens[$index]);
                $mapped = $fn($text, $position === 0, $position === count($runs) - 1);
                if ($mapped !== $text) {
                    $tokens[$index] = self::escapeTextLike($mapped, $tokens[$index]);
                }
            }
            $lines[$lineIndex] = implode("", $tokens);
        }

        return $lines;
    }


    /**
     * Tells if one of the lines holds text other than white space outside its tags. An entity counts as text.
     *
     * @param array<string> $lines
     */
    public static function hasVisibleText(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim(preg_replace(self::TAG_REGEX, "", $line)) !== "") {
                return true;
            }
        }

        return false;
    }


    /**
     * Removes tags and decodes entities, for formats without markup.
     */
    public static function plainText(string $text): string
    {
        return self::decodeEntities(self::stripAllTags($text));
    }


    /**
     * Removes tags, decodes entities and trims the text, for example "<i>Hi</i> &amp; bye " becomes "Hi & bye".
     *
     * @internal
     */
    public static function visibleText(string $text): string
    {
        return trim(self::plainText($text));
    }


    /**
     * Returns the name of the first <v> tag, with entities decoded and spaces trimmed, or null when $text has no <v> tag.
     *
     * @internal
     */
    public static function speaker(string $text): ?string
    {
        return preg_match('/' . self::VOICE_TAG . '/', $text, $match) === 1 ? trim(self::decodeEntities($match[2])) : null;
    }


    /**
     * Puts the <v> tag of $speaker before the first line that holds more than white space. An empty $speaker adds no tag.
     *
     * @param list<string> $lines
     * @return list<string>
     *
     * @internal
     */
    public static function addSpeaker(array $lines, string $speaker): array
    {
        if ($speaker === "") {
            return $lines;
        }

        foreach ($lines as $index => $line) {
            if (trim($line) !== "") {
                $lines[$index] = self::voiceTag($speaker) . ltrim($line);
                break;
            }
        }

        return $lines;
    }


    /**
     * Removes style tag pairs without text, such as <i></i> or <b><i></i></b>, until none is left.
     * With $withSpaces, a pair that holds only white space goes too and leaves the white space.
     *
     * @internal
     */
    public static function removeEmptyTagPairs(string $text, bool $ignoreCase = false, bool $withSpaces = false): string
    {
        $pattern = '/<(' . implode("|", self::STYLE_TAGS) . ')(?=[\s.>])[^<>]*>(' . ($withSpaces ? '\s*' : '') . ')<\/\1>/'
            . ($ignoreCase ? "i" : "");
        do {
            $text = preg_replace($pattern, '$2', $text, -1, $count) ?? $text;
        } while ($count > 0);

        return $text;
    }


    /**
     * Finds the tags of $tagNames that do not pair up, case-insensitively.
     * A closing tag closes the last open tag of its name.
     *
     * @param list<string> $lines
     * @param list<string> $tagNames lowercase tag names, for example ["b", "i"]
     * @return array{stray: list<array{int, int, int}>, open: list<string>} "stray" holds the line index, offset and
     *         length of each closing tag without an open tag. "open" holds the tag names that are still open after
     *         the last line.
     *
     * @internal
     */
    public static function unbalancedTags(array $lines, array $tagNames): array
    {
        $pattern = '/<(\/?)(' . implode("|", $tagNames) . ')(?=[\s.>])[^<>]*>/i';
        $open    = [];
        $stray   = [];
        foreach ($lines as $lineIndex => $line) {
            preg_match_all($pattern, $line, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                $name = strtolower($tag[2][0]);
                if ($tag[1][0] === "") {
                    $open[] = $name;
                    continue;
                }

                $match = array_search($name, array_reverse($open, true), true);
                if ($match === false) {
                    $stray[] = [$lineIndex, $tag[0][1], strlen($tag[0][0])];
                } else {
                    unset($open[$match]);
                }
            }
        }

        return ["stray" => $stray, "open" => array_values($open)];
    }


    /**
     * Removes tags, decodes entities, trims each line and drops the lines that end up empty, for formats without markup.
     *
     * @param list<string> $lines
     * @return list<string>
     *
     * @internal
     */
    public static function plainLines(array $lines): array
    {
        return array_values(array_filter(
            array_map(self::visibleText(...), $lines),
            fn (string $line): bool => $line !== ""
        ));
    }


    /**
     * Counts the characters of the text without tags and entities and without leading and trailing spaces.
     */
    public static function visibleLength(string $text): int
    {
        return self::countCharacters(self::visibleText($text));
    }


    /**
     * Counts UTF-8 characters, or bytes for invalid UTF-8 such as the Latin-1 bytes that MicroDVD keeps.
     *
     * @internal
     */
    public static function countCharacters(string $text): int
    {
        // mbstring is not part of a default PHP build, but PCRE is. preg_match_all() fails on invalid UTF-8.
        return preg_match_all('/./su', $text) ?: strlen($text);
    }


    /**
     * Splits text into UTF-8 characters, or into bytes when it is not valid UTF-8.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text);
    }


    /**
     * Splits text at white space into words. The text must hold no tags.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function words(string $text): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Invalid UTF-8, such as the Latin-1 bytes that MicroDVD keeps, makes the /u pattern fail.
        return $words === false ? preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) : $words;
    }


    /**
     * Joins the lines of text with a space, for formats that hold one line per cue or comment.
     *
     * @internal
     */
    public static function toSingleLine(string $text): string
    {
        return preg_replace('/\s*\n\s*/', " ", trim($text));
    }


    /**
     * Returns the core markup tags that are open after $text, given the tags in $openTags that are open before it.
     *
     * @param list<array{name: string, tag: string}> $openTags
     * @return list<array{name: string, tag: string}> the lowercase tag name and the opening tag as written
     *
     * @internal
     */
    public static function openCoreTags(string $text, array $openTags = []): array
    {
        preg_match_all(self::TAG_REGEX, $text, $tags);
        foreach ($tags[0] as $tag) {
            preg_match('/^<(\/?)([a-zA-Z]*)/', $tag, $match);
            [, $slash, $name] = $match;
            $name             = strtolower($name);
            if (!in_array($name, self::CORE_TAGS, true)) {
                continue;
            }

            if ($slash === "") {
                $openTags[] = ["name" => $name, "tag" => $tag];
                continue;
            }

            for ($index = count($openTags) - 1; $index >= 0; $index--) {
                if ($openTags[$index]["name"] === $name) {
                    array_splice($openTags, $index, 1);
                    break;
                }
            }
        }

        return $openTags;
    }


    /**
     * Returns the closing tags for the result of openCoreTags(), the last opened tag first.
     *
     * @param list<array{name: string, tag: string}> $openTags
     *
     * @internal
     */
    public static function closeCoreTags(array $openTags): string
    {
        $closing = "";
        foreach (array_reverse($openTags) as $openTag) {
            $closing .= "</{$openTag["name"]}>";
        }

        return $closing;
    }


    /**
     * Returns the color attribute of a <font> tag as written, such as "#FF0000" for <FONT COLOR='#FF0000'>, or null.
     * $attributes holds the attributes or the whole tag.
     * The value can have double quotes, single quotes or no quotes, and the name can have any case.
     *
     * @internal
     */
    public static function fontColor(string $attributes): ?string
    {
        if (!preg_match("/\bcolor\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"'>]+))/i", $attributes, $color)) {
            return null;
        }

        return ($color[1] ?? "") . ($color[2] ?? "") . ($color[3] ?? "");
    }


    /**
     * Returns the text color and the background color of WebVTT classes such as ".bg_black.yellow", or null for none.
     * When several color classes apply, the last one in the default style sheet wins, as in a browser.
     *
     * @return array{color: ?string, background: ?string} colors as "#rrggbb"
     *
     * @internal
     */
    public static function webVttClassColors(string $classes): array
    {
        $names  = explode(".", $classes);
        $colors = ["color" => null, "background" => null];
        foreach (self::WEBVTT_COLORS as $name => $hex) {
            if (in_array($name, $names, true)) {
                $colors["color"] = $hex;
            }
            if (in_array("bg_$name", $names, true)) {
                $colors["background"] = $hex;
            }
        }

        return $colors;
    }


    /**
     * Turns WebVTT <c> tags with a color class into <font> tags, for formats that write <font color>.
     * For example "<c.yellow>Hi</c>" becomes "<font color=\"#ffff00\">Hi</font>". Other <c> tags stay as they are.
     *
     * @internal
     */
    public static function webVttColorsToFont(string $text): string
    {
        if (!str_contains($text, "<c")) {
            return $text;
        }

        $tokens = self::splitTags($text);
        $open   = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                continue;
            }
            if (preg_match('/^<c((?:\.[^\s.<>]*)*)(?:\s[^<>]*)?>$/', $token, $matches) === 1) {
                $color          = self::webVttClassColors($matches[1])["color"];
                $open[]         = $color !== null;
                $tokens[$index] = $color === null ? $token : "<font color=\"$color\">";
            } elseif (preg_match('/^<\/c\s*>$/', $token) === 1 && array_pop($open) === true) {
                $tokens[$index] = "</font>";
            }
        }

        return implode("", $tokens);
    }


    /**
     * Turns <font> tags into WebVTT <c> tags, for example <font color="#ffff00"> becomes <c.yellow>.
     * It drops a <font> tag whose color is not one of WEBVTT_COLORS, and a <font> tag without a color.
     *
     * @return array{string, list<string>} the text and each dropped color as written
     *
     * @internal
     */
    public static function fontToWebVttColors(string $text): array
    {
        if (stripos($text, "<font") === false) {
            return [$text, []];
        }

        $tokens  = self::splitTags($text);
        $open    = [];
        $dropped = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                continue;
            }
            if (preg_match('/^<font\b/i', $token) === 1) {
                $color = self::fontColor($token);
                $class = $color === null ? null : self::webVttColorClass($color);
                if ($class === null && $color !== null && trim($color) !== "") {
                    $dropped[] = $color;
                }
                $open[]         = $class !== null;
                $tokens[$index] = $class === null ? "" : "<c.$class>";
            } elseif (preg_match('/^<\/font\s*>$/i', $token) === 1) {
                $tokens[$index] = array_pop($open) === true ? "</c>" : "";
            }
        }

        return [implode("", $tokens), $dropped];
    }


    /**
     * Returns the WebVTT class of a color such as "#FFFF00", "#ff0" or "yellow", or null when no class has the color.
     */
    private static function webVttColorClass(string $color): ?string
    {
        $color = strtolower(trim(self::decodeEntities($color)));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $digits) === 1) {
            $color = "#$digits[1]$digits[1]$digits[2]$digits[2]$digits[3]$digits[3]";
        }
        if (isset(self::WEBVTT_COLORS[$color])) {
            return $color;
        }

        $class = array_search($color, self::WEBVTT_COLORS, true);

        return $class === false ? null : $class;
    }


    /**
     * Swaps the first and the last byte of a 6-digit hex color. ASS and MicroDVD write colors in BGR order.
     * For example, "0000FF" in BGR order becomes "FF0000" in RGB.
     *
     * @internal
     */
    public static function bgrToRgb(string $bgr): string
    {
        return substr($bgr, 4, 2) . substr($bgr, 2, 2) . substr($bgr, 0, 2);
    }


    /**
     * Swaps the first and the last byte of a 6-digit hex color.
     * For example, "FF0000" in RGB order becomes "0000FF" in BGR.
     *
     * @internal
     */
    public static function rgbToBgr(string $rgb): string
    {
        return self::bgrToRgb($rgb);
    }


    /**
     * Returns the seconds of a core word timestamp such as "<00:01:02.500>", or null for other text.
     */
    public static function wordTimestampSeconds(string $timestamp): ?float
    {
        if (preg_match('/^<' . self::WORD_TIMESTAMP . '>$/', $timestamp) !== 1) {
            return null;
        }

        [$hours, $minutes, $seconds, $fraction] = preg_split('/[:.]/', substr($timestamp, 1, -1));

        return Timecode::toSeconds((int) $hours, (int) $minutes, (int) $seconds, $fraction);
    }


    /**
     * Replaces each core word timestamp in $text with the timestamp of $map(seconds). A time below 0 becomes 0.
     *
     * @param callable(float): float $map
     */
    public static function mapWordTimestamps(string $text, callable $map): string
    {
        if (!str_contains($text, "<")) {
            return $text;
        }

        return preg_replace_callback(
            self::WORD_TIMESTAMP_REGEX,
            fn (array $match): string => "<" . self::coreTimestamp(max(0.0, $map(self::wordTimestampSeconds($match[1])))) . ">",
            $text
        ) ?? $text;
    }


    /**
     * Formats seconds as the body of a core word timestamp, for example 62.5 becomes "00:01:02.500".
     *
     * @internal
     */
    public static function coreTimestamp(float $seconds): string
    {
        return sprintf("%02d:%02d:%02d.%03d", ...Timecode::milliseconds($seconds));
    }
}
