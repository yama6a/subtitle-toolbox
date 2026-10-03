<?php

declare(strict_types=1);

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
     * Returns a <v> tag for the speaker name with &, < and > escaped and quotes kept. $class holds voice classes such as ".loud".
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
     */
    public static function unescapeText(string $text): string
    {
        return strtr($text, ["&lt;" => "<", "&gt;" => ">", "&amp;" => "&"]);
    }


    /**
     * Escapes a changed text run as escapeText() does, but keeps & and > unescaped where its raw form $raw has them
     * unescaped, as WebVTT text does. An & before an entity name gets escaped.
     */
    public static function escapeTextLike(string $text, string $raw): string
    {
        $entity = '&(?=[a-zA-Z][a-zA-Z0-9]*;|#[0-9]+;|#[xX][0-9a-fA-F]+;)';
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
     */
    public static function splitTags(string $line): array
    {
        return preg_split('/(<[^<>]*>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    }


    /**
     * Calls fn (string $text, bool $first, bool $last): string for each text run between tags, with &lt;, &gt; and &amp;
     * decoded. $first and $last mark the first and the last run of the line that holds text.
     *
     * @param list<string> $lines
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
            if (trim(preg_replace('/<[^<>]*>/', "", $line)) !== "") {
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
     * Removes tags, decodes entities, trims each line and drops the lines that end up empty, for formats without markup.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function plainLines(array $lines): array
    {
        return array_values(array_filter(
            array_map(fn (string $line): string => trim(self::plainText($line)), $lines),
            fn (string $line): bool => $line !== ""
        ));
    }


    /**
     * Counts the characters of the text without tags and entities and without leading and trailing spaces.
     */
    public static function visibleLength(string $text): int
    {
        return self::countCharacters(trim(self::plainText($text)));
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
     * Splits text into UTF-8 characters, or into bytes when it is not valid UTF-8.
     *
     * @return list<string>
     */
    public static function characters(string $text): array
    {
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text);
    }


    /**
     * Splits text at white space into words. The text must hold no tags.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Invalid UTF-8, such as the Latin-1 bytes that MicroDVD keeps, makes the /u pattern fail.
        return $words === false ? preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) : $words;
    }


    /**
     * Joins the lines of text with a space, for formats that hold one line per cue or comment.
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
     */
    public static function openCoreTags(string $text, array $openTags = []): array
    {
        preg_match_all('/<(\/?)([a-zA-Z]+)[^>]*>/', $text, $tags, PREG_SET_ORDER);
        foreach ($tags as [$tag, $slash, $name]) {
            $name = strtolower($name);
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
     * Returns the seconds of a core word timestamp such as "<00:01:02.500>", or null for other text.
     */
    public static function wordTimestampSeconds(string $timestamp): ?float
    {
        if (preg_match('/^<(\d{2,}):([0-5]\d):([0-5]\d\.\d{3})>$/', $timestamp, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 3600 + (int) $match[2] * 60 + (float) $match[3];
    }


    /**
     * Formats seconds as the body of a core word timestamp, for example 62.5 becomes "00:01:02.500".
     */
    public static function coreTimestamp(float $seconds): string
    {
        return sprintf("%02d:%02d:%02d.%03d", ...Timecode::milliseconds($seconds));
    }
}
