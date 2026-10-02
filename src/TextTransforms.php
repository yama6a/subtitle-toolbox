<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

trait TextTransforms
{
    /**
     * Calls fn (string $text, SubtitleCue $cue): string for each text run between tags, with &lt;, &gt; and &amp; decoded.
     */
    public function mapText(callable $fn): self
    {
        return $this->textTransformsMapRuns(fn (string $text, SubtitleCue $cue): string => $fn($text, $cue));
    }


    /**
     * Calls fn (string $line, SubtitleCue $cue): string for each line, with its tags and entities.
     */
    public function mapLines(callable $fn): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array =>
            array_map(fn (string $line): string => $fn($line, $cue), $cue->getLines()));
    }


    /**
     * Replaces $search with $replace in the text between tags. $search is a PCRE pattern with delimiters when $regex is true.
     */
    public function replaceText(string $search, string $replace, bool $regex = false, bool $caseSensitive = true): self
    {
        if ($search === "") {
            throw new InvalidArgumentException("The search text must not be empty.");
        }

        if ($regex) {
            $pattern = $caseSensitive ? $search : $search[0] . "(?i)" . substr($search, 1);
            if (@preg_match($pattern, "") === false) {
                throw new InvalidArgumentException("The regular expression $search is invalid: " . preg_last_error_msg());
            }

            return $this->textTransformsMapRuns(fn (string $text): string =>
                preg_replace($pattern, $replace, $text) ?? $text);
        }

        if ($caseSensitive) {
            return $this->textTransformsMapRuns(fn (string $text): string => str_replace($search, $replace, $text));
        }

        $pattern     = "/" . preg_quote($search, "/") . "/iu";
        $replacement = str_replace(["\\", "$"], ["\\\\", "\\$"], $replace);

        return $this->textTransformsMapRuns(fn (string $text): string =>
            preg_replace($pattern, $replacement, $text) ?? str_ireplace($search, $replace, $text));
    }


    /**
     * Removes all tags except the tag names in $keepTags, for example ["i"].
     *
     * @param list<string> $keepTags
     */
    public function stripFormatting(array $keepTags = [], bool $keepWordTimestamps = true): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => array_map(
            function (string $line) use ($keepTags, $keepWordTimestamps): string {
                if (!$keepWordTimestamps) {
                    return Markup::keepTags($line, $keepTags);
                }

                $parts = preg_split(Markup::WORD_TIMESTAMP_REGEX, $line, -1, PREG_SPLIT_DELIM_CAPTURE);

                return implode("", array_map(
                    fn (string $part, int $index): string => $index % 2 === 1 ? $part : Markup::keepTags($part, $keepTags),
                    $parts,
                    array_keys($parts)
                ));
            },
            $cue->getLines()
        ));
    }


    /**
     * Changes the case of the text between tags. $mode is "upper", "lower" or "sentence".
     * $language "tr" or "az" maps i to İ and ı to I.
     */
    public function changeCase(string $mode, ?string $language = null): self
    {
        $turkic = in_array(strtolower(explode("-", str_replace("_", "-", $language ?? ""))[0]), ["tr", "az"], true);

        return match ($mode) {
            "upper"    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsUpper($text, $turkic)),
            "lower"    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsLower($text, $turkic)),
            "sentence" => $this->textTransformsSentenceCase($turkic),
            default    => throw new InvalidArgumentException("The case mode must be upper, lower or sentence, got $mode."),
        };
    }


    private function textTransformsSentenceCase(bool $turkic): self
    {
        $currentCue       = null;
        $capitalizeNext   = true;
        $afterPunctuation = false;

        return $this->textTransformsMapRuns(
            function (string $text, SubtitleCue $cue, bool $startsLine)
                use (&$currentCue, &$capitalizeNext, &$afterPunctuation, $turkic): string {
                if ($cue !== $currentCue) {
                    $currentCue       = $cue;
                    $capitalizeNext   = true;
                    $afterPunctuation = false;
                }
                if ($startsLine && $afterPunctuation) {
                    $capitalizeNext = true;
                }

                $result = "";
                foreach (self::textTransformsCharacters(self::textTransformsLower($text, $turkic)) as $char) {
                    if (preg_match('/^[\p{L}\p{N}]$/u', $char) === 1 || (strlen($char) === 1 && ctype_alnum($char))) {
                        if ($capitalizeNext) {
                            $char = self::textTransformsTitle($char, $turkic);
                        }
                        $capitalizeNext   = false;
                        $afterPunctuation = false;
                    } elseif (in_array($char, [".", "!", "?"], true)) {
                        $afterPunctuation = true;
                    } elseif ($afterPunctuation && ctype_space($char)) {
                        $capitalizeNext = true;
                    }
                    $result .= $char;
                }

                return $result;
            }
        );
    }


    /**
     * Calls $fn (string $text, SubtitleCue $cue, bool $startsLine) for each text run between tags, in order.
     */
    private function textTransformsMapRuns(callable $fn): self
    {
        return $this->textTransformsMapCues(function (SubtitleCue $cue) use ($fn): array {
            $lines = [];
            foreach ($cue->getLines() as $line) {
                $tokens = preg_split('/(<[^<>]*>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
                $first  = true;
                foreach ($tokens as $index => $token) {
                    if ($index % 2 === 1 || $token === "") {
                        continue;
                    }

                    $text   = strtr($token, ["&lt;" => "<", "&gt;" => ">", "&amp;" => "&"]);
                    $mapped = $fn($text, $cue, $first);
                    $first  = false;
                    if ($mapped !== $text) {
                        $tokens[$index] = self::textTransformsEscape($mapped, $token);
                    }
                }
                $lines[] = implode("", $tokens);
            }

            return $lines;
        });
    }


    /**
     * Sets the lines that $fn returns for each cue. Removes a cue that had visible text before but has none after.
     *
     * @param callable(SubtitleCue): list<string> $fn
     */
    private function textTransformsMapCues(callable $fn): self
    {
        $removed = false;
        foreach ($this->cues as $index => $cue) {
            $hadText = self::textTransformsHasVisibleText($cue->getLines());
            $cue->setLinesByArray($fn($cue));

            if ($hadText && !self::textTransformsHasVisibleText($cue->getLines())) {
                $this->removeCue($index, false);
                $removed = true;
            }
        }

        if ($removed) {
            $this->reIndexCues();
        }

        return $this;
    }


    /**
     * Keeps & and > unescaped where $raw has them unescaped, as WebVTT text does. An & before an entity name gets escaped.
     */
    private static function textTransformsEscape(string $text, string $raw): string
    {
        $entity = '&(?=[a-zA-Z][a-zA-Z0-9]*;|#[0-9]+;|#[xX][0-9a-fA-F]+;)';
        $text   = preg_match("/&(?!lt;|gt;|amp;)/", $raw) === 1
            ? (preg_replace("/$entity/", "&amp;", $text) ?? str_replace("&", "&amp;", $text))
            : str_replace("&", "&amp;", $text);
        $text   = str_replace("<", "&lt;", $text);

        return str_contains($raw, ">") ? $text : str_replace(">", "&gt;", $text);
    }


    /**
     * @param list<string> $lines
     */
    private static function textTransformsHasVisibleText(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim(preg_replace('/<[^<>]*>/', "", $line)) !== "") {
                return true;
            }
        }

        return false;
    }


    private static function textTransformsUsesMultibyte(string $text): bool
    {
        return extension_loaded("mbstring") && mb_check_encoding($text, "UTF-8");
    }


    private static function textTransformsUpper(string $text, bool $turkic): string
    {
        if (!self::textTransformsUsesMultibyte($text)) {
            return strtoupper($text);
        }

        return mb_strtoupper($turkic ? str_replace("i", "İ", $text) : $text, "UTF-8");
    }


    private static function textTransformsLower(string $text, bool $turkic): string
    {
        if (!self::textTransformsUsesMultibyte($text)) {
            return strtolower($text);
        }

        $lower = mb_strtolower($turkic ? str_replace(["İ", "I"], ["i", "ı"], $text) : $text, "UTF-8");

        // PHP 8.2 lacks the Unicode Final_Sigma rule, which PHP 8.3 added to mb_strtolower().
        return preg_replace('/(?<=\p{L})σ(?!\p{L})/u', "ς", $lower);
    }


    private static function textTransformsTitle(string $char, bool $turkic): string
    {
        if (!self::textTransformsUsesMultibyte($char)) {
            return strtoupper($char);
        }

        return mb_convert_case($turkic && $char === "i" ? "İ" : $char, MB_CASE_TITLE, "UTF-8");
    }


    /**
     * @return list<string> UTF-8 characters, or bytes when $text is not valid UTF-8
     */
    private static function textTransformsCharacters(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? str_split($text) : $chars;
    }
}
