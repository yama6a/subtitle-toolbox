<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
trait TextTransforms
{
    private const TEXT_TRANSFORMS_SENTENCE_GAP = 2.0;


    /**
     * Calls $fn for each text run between tags, with entities decoded as Markup::mapTextRuns() does.
     *
     * @param callable(string $text, SubtitleCue $cue): string $fn
     */
    public function mapText(callable $fn): self
    {
        return $this->textTransformsMapRuns(fn (string $text, SubtitleCue $cue): string => $fn($text, $cue));
    }


    /**
     * Calls $fn for each line, with its tags and entities.
     *
     * @param callable(string $line, SubtitleCue $cue): string $fn
     */
    public function mapLines(callable $fn): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array =>
            array_map(fn (string $line): string => $fn($line, $cue), $cue->getLines()));
    }


    /**
     * Replaces $search with $replace in the text between tags.
     */
    public function replaceText(string $search, string $replace, ?ReplaceTextOptions $options = null): self
    {
        $options ??= new ReplaceTextOptions();
        $regex         = $options->regex;
        $caseSensitive = $options->caseSensitive;
        if ($search === "") {
            throw new InvalidArgumentException("The search text must not be empty.");
        }

        if ($regex) {
            $pattern = $caseSensitive ? $search : $search[0] . "(?i)" . substr($search, 1);
            if (@preg_match($pattern, "") === false) {
                throw new InvalidArgumentException("The regular expression \"$search\" is not valid: " . preg_last_error_msg() . ".");
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
     * Changes the case of the text between tags. $language "tr" or "az" maps i to İ and ı to I.
     */
    public function changeCase(CaseMode $mode, ?string $language = null): self
    {
        $primary = StringHelpers::primaryLanguage($language);
        $turkic  = in_array($primary, ["tr", "az"], true);

        return match ($mode) {
            CaseMode::Upper    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsUpper($text, $turkic)),
            CaseMode::Lower    => $this->textTransformsMapRuns(fn (string $text): string => self::textTransformsLower($text, $turkic)),
            CaseMode::Sentence => $this->textTransformsSentenceCase($turkic, $primary === "en" || $language === null),
        };
    }


    /**
     * Returns plain $text in upper or lower case as changeCase() with CaseMode::Upper or CaseMode::Lower writes it.
     *
     * @internal
     */
    public static function toUpperOrLower(string $text, bool $upper): string
    {
        return $upper ? self::textTransformsUpper($text, false) : self::textTransformsLower($text, false);
    }


    private function textTransformsSentenceCase(bool $turkic, bool $english): self
    {
        $currentCue       = null;
        $previousEnd      = null;
        $endsSentence     = true;
        $capitalizeNext   = true;
        $afterPunctuation = false;

        return $this->textTransformsMapRuns(
            function (string $text, SubtitleCue $cue, bool $startsLine)
                use (&$currentCue, &$previousEnd, &$endsSentence, &$capitalizeNext, &$afterPunctuation, $turkic, $english): string {
                if ($cue !== $currentCue) {
                    $currentCue       = $cue;
                    $capitalizeNext   = $endsSentence || ($previousEnd !== null
                        && $cue->getStart() - $previousEnd >= self::TEXT_TRANSFORMS_SENTENCE_GAP - Timecode::EPSILON);
                    $afterPunctuation = false;
                    $previousEnd      = $cue->getEnd();
                    $endsSentence     = self::textTransformsEndsSentence(Markup::visibleText(implode("\n", $cue->getLines())));
                }
                if ($startsLine && $afterPunctuation) {
                    $capitalizeNext = true;
                }

                $text = self::textTransformsSentenceCaseRun($text, $turkic, $capitalizeNext, $afterPunctuation);

                return $english ? self::textTransformsEnglishI($text) : $text;
            }
        );
    }


    /**
     * Lowers a text run and capitalizes its first letter or digit after the start of a sentence.
     */
    private static function textTransformsSentenceCaseRun(string $text, bool $turkic, bool &$capitalizeNext, bool &$afterPunctuation): string
    {
        $result = "";
        foreach (Markup::characters(self::textTransformsLower($text, $turkic)) as $char) {
            if (preg_match('/^[\p{L}\p{N}]$/u', $char) === 1 || (strlen($char) === 1 && ctype_alnum($char))) {
                if ($capitalizeNext) {
                    $char = self::textTransformsTitle($char, $turkic);
                }
                $capitalizeNext   = false;
                $afterPunctuation = false;
            } elseif (in_array($char, [".", "!", "?", "\u{2026}"], true)) {
                $afterPunctuation = true;
            } elseif ($afterPunctuation && ctype_space($char)) {
                $capitalizeNext = true;
            }
            $result .= $char;
        }

        return $result;
    }


    private static function textTransformsEndsSentence(string $text): bool
    {
        return (preg_match('/[.!?\x{2026}][\p{Pe}\p{Pi}\p{Pf}"\']*$/u', $text)
            ?: preg_match('/[.!?][)\]}"\']*$/', $text)) === 1;
    }


    /**
     * Writes the English pronoun "i" and its contractions such as "i'm" in upper case. "i.e." stays lower case.
     */
    private static function textTransformsEnglishI(string $text): string
    {
        return preg_replace('/(?<![\p{L}\p{N}\'\x{2019}]|\p{L}\.)i(?=(?:[\'\x{2019}](?:m|ll|ve|d))?(?![\p{L}\p{N}\'\x{2019}]|\.\p{L}))/u', "I", $text)
            ?? preg_replace('/(?<![A-Za-z0-9\']|[A-Za-z]\.)i(?=(?:\'(?:m|ll|ve|d))?(?![A-Za-z0-9\']|\.[A-Za-z]))/', "I", $text);
    }


    /**
     * Calls $fn for each text run between tags, in order.
     *
     * @param callable(string $text, SubtitleCue $cue, bool $startsLine): string $fn
     */
    private function textTransformsMapRuns(callable $fn): self
    {
        return $this->textTransformsMapCues(fn (SubtitleCue $cue): array => Markup::mapTextRuns(
            $cue->getLines(),
            fn (string $text, bool $first): string => $fn($text, $cue, $first)
        ));
    }


    /**
     * Sets the lines that $fn returns for each cue. Removes a cue that had visible text before but has none after.
     *
     * @param callable(SubtitleCue): list<string> $fn
     */
    private function textTransformsMapCues(callable $fn): self
    {
        $this->setLinesAndRemoveEmptied(fn (SubtitleCue $cue): array => $fn($cue));

        return $this;
    }


    private static function textTransformsUpper(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtoupper($text);
        }

        return mb_strtoupper($turkic ? str_replace("i", "İ", $text) : $text, "UTF-8");
    }


    private static function textTransformsLower(string $text, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($text)) {
            return strtolower($text);
        }

        $lower = mb_strtolower($turkic ? str_replace(["İ", "I"], ["i", "ı"], $text) : $text, "UTF-8");

        // PHP 8.2 lacks the Unicode Final_Sigma rule, which PHP 8.3 added to mb_strtolower().
        return preg_replace('/(?<=\p{L})σ(?!\p{L})/u', "ς", $lower);
    }


    private static function textTransformsTitle(string $char, bool $turkic): string
    {
        if (!StringHelpers::canUseMultibyte($char)) {
            return strtoupper($char);
        }

        return mb_convert_case($turkic && $char === "i" ? "İ" : $char, MB_CASE_TITLE, "UTF-8");
    }
}
