<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * The replace list rules port GetReplaceWord(), StripAffixes(), ReplaceWord() and SkipAddLineEnding() of
 * OcrFixReplaceList2.cs of Subtitle Edit, MIT license, at commit e1b8546:
 * https://github.com/SubtitleEdit/subtitleedit/blob/e1b854665b40bf6e04271c2ec64084947060e632/src/libuilogic/Ocr/FixEngine/OcrFixReplaceList2.cs
 */
final class CommonErrorFixer
{
    private const DASHES       = '\-\x{2010}\x{2013}\x{2014}';
    private const NOT_IN_WORD  = '(?<![\p{L}\p{N}\'\x{2019}])';
    private const WORD_ENDS    = '(?![\p{L}\p{N}\'\x{2019}])';
    private const SPACES       = '[ \t\x{00A0}]';
    private const BOUNDARY     = '/^[\p{P}\s<>`\x{00B4}\x{266A}]$/u';
    private const L_CONSONANTS = [
        "en" => "bcdfghjkmnpqrstvwxz",
        "de" => "bcdfghjkmnpqrstvwxz",
        "es" => "bcdfghjkmnpqrstvwxz",
        "fr" => "bcdfghjklmnpqrstvwxz",
    ];


    /**
     * Fixes common text and OCR errors in the cue text and reports each change.
     */
    public static function apply(Subtitle $subtitle, ?CommonErrorOptions $options = null): CommonErrorReport
    {
        $options ??= new CommonErrorOptions();
        return self::run($subtitle, $options, true);
    }


    /**
     * Returns the report that apply() would return, and leaves the subtitle as it is.
     */
    public static function preview(Subtitle $subtitle, ?CommonErrorOptions $options = null): CommonErrorReport
    {
        $options ??= new CommonErrorOptions();
        return self::run($subtitle, $options, false);
    }


    private static function run(Subtitle $subtitle, CommonErrorOptions $options, bool $change): CommonErrorReport
    {
        $language    = self::language($options->language ?? $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE));
        $cues        = $subtitle->getCues();
        $indexes     = array_keys($cues);
        $fixes       = [];
        $removedCues = new \SplObjectStorage();

        foreach ($indexes as $position => $index) {
            $cue   = $cues[$index];
            $lines = array_values($cue->getLines());
            if ($lines === []) {
                continue;
            }

            $next      = $cues[$indexes[$position + 1] ?? -1] ?? null;
            $continues = $next !== null && $next->getStart() - $cue->getEnd() <= 0.6
                         && preg_match('/^\p{Ll}/u', implode("\n", Markup::plainLines($next->getLines()))) === 1;
            $original  = $lines;
            foreach (CommonErrorRule::cases() as $rule) {
                $fixed = self::applyRule($rule, $lines, $options, $language, $continues);
                if ($fixed !== $lines) {
                    $fixes[] = new AppliedFix($index, $rule, implode("\n", $lines), implode("\n", $fixed));
                    $lines   = $fixed;
                }
            }

            if (!$change || $lines === $original) {
                continue;
            }
            $cue->setLines($lines);
            if (Markup::plainLines($cue->getLines()) === [] && Markup::plainLines($original) !== []) {
                $removedCues[$cue] = true;
            }
        }

        if ($removedCues->count() > 0) {
            $subtitle->removeCuesWhere(fn (SubtitleCue $cue): bool => isset($removedCues[$cue]));
        }

        return new CommonErrorReport($fixes);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function applyRule(CommonErrorRule $rule, array $lines, CommonErrorOptions $options, ?string $language,
                                      bool $continues): array
    {
        $enabled = $rule === CommonErrorRule::ReplaceList ? $options->replaceList !== null : $options->{$rule->value};
        if (!$enabled) {
            return $lines;
        }

        return match ($rule) {
            CommonErrorRule::ReplaceList     => self::replaceList($lines, $options->replaceList, $continues),
            CommonErrorRule::UnbalancedTags  => self::unbalancedTags($lines),
            CommonErrorRule::EmptyTags       => array_map(fn (string $line): string =>
                Markup::removeEmptyTagPairs($line, ignoreCase: true, withSpaces: true), $lines),
            CommonErrorRule::OcrPipe         => Markup::mapTextRuns($lines, fn (string $text): string => self::ocrPipe($text, $language)),
            CommonErrorRule::OcrZeroInWords  => Markup::mapTextRuns($lines, fn (string $text, bool $first): string => self::ocrZero($text, $first)),
            CommonErrorRule::OcrLowercaseL   => Markup::mapTextRuns($lines, fn (string $text): string => self::ocrLowercaseL($text, $language)),
            CommonErrorRule::Ellipsis        => Markup::mapTextRuns($lines, fn (string $text): string => self::replace(
                '/\.(?: ?\.){2,}' . ($options->unicodeEllipsis ? '|\x{2026}' : '') . '/u',
                $options->unicodeEllipsis ? "\u{2026}" : "...",
                $text
            )),
            CommonErrorRule::DoubleSpaces    => self::doubleSpaces($lines),
            CommonErrorRule::SpaceBeforePunctuation       => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::spaceBeforePunctuation($text, $language)),
            CommonErrorRule::MissingSpaceAfterPunctuation => Markup::mapTextRuns($lines, fn (string $text): string =>
                self::missingSpaceAfterPunctuation($text)),
            CommonErrorRule::DialogueDashes  => Markup::mapTextRuns($lines, fn (string $text, bool $first): string => !$first ? $text : self::replace(
                '/^[' . self::DASHES . '](?![' . self::DASHES . '])' . self::SPACES . '*(?=[^\s\p{N}])/u',
                $options->dialogueDashStyle->value,
                $text
            )),
        };
    }


    /**
     * Returns "en", "de", "fr" or "es" for a code such as "en-US" or "deu", and null for other languages.
     */
    private static function language(?string $code): ?string
    {
        return match (StringHelpers::primaryLanguage($code)) {
            "en", "eng"        => "en",
            "de", "deu", "ger" => "de",
            "fr", "fra", "fre" => "fr",
            "es", "spa"        => "es",
            default            => null,
        };
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function unbalancedTags(array $lines): array
    {
        $tags = Markup::unbalancedTags($lines, Markup::STYLE_TAGS);
        foreach (array_reverse($tags["stray"]) as [$lineIndex, $offset, $length]) {
            $lines[$lineIndex] = substr_replace($lines[$lineIndex], "", $offset, $length);
        }
        $last = count($lines) - 1;
        foreach (array_reverse($tags["open"]) as $name) {
            $lines[$last] .= "</$name>";
        }

        return $lines;
    }


    private static function ocrPipe(string $text, ?string $language): string
    {
        $offset = 0;
        while (($offset = strpos($text, "|", $offset)) !== false) {
            $before = substr($text, 0, $offset);
            $after  = substr($text, $offset + 1);
            $letter = match (true) {
                preg_match('/\p{Ll}$/u', $before) === 1                   => "l",
                preg_match('/\p{L}$/u', $before) === 1,
                preg_match('/^\p{L}/u', $after) === 1                     => "I",
                preg_match('/^[\'\x{2019}]\p{L}/u', $after) === 1         => ["en" => "I", "fr" => "l"][$language ?? ""] ?? "|",
                $language === "en" && preg_match('/(?:^|[\s"\'(' . self::DASHES . '])$/u', $before) === 1
                    && preg_match('/^(?:$|[\s.,!?;:"])/u', $after) === 1  => "I",
                default                                                    => "|",
            };
            $text[$offset] = $letter;
            $offset++;
        }

        return $text;
    }


    private static function ocrZero(string $text, bool $startsLine): string
    {
        $pattern = '/[\p{L}\p{N}\'\x{2019}]*0[\p{L}\p{N}\'\x{2019}]*/u';

        return self::replaceCallback($pattern, function (array $match) use ($text, $startsLine): string {
            [$word, $offset] = $match[0];
            if (preg_match_all('/\p{N}/u', $word) !== substr_count($word, "0") || preg_match_all('/\p{L}/u', $word) < 2) {
                return $word;
            }

            $before        = substr($text, 0, $offset);
            $opening       = '[\s"\'\x{00BF}\x{00A1}' . self::DASHES . ']*$';
            $sentenceStart = ($startsLine && preg_match("/^$opening/u", $before) === 1)
                             || preg_match("/[.!?]\\s+$opening/u", $before) === 1;
            $result        = str_replace("0", preg_match('/\p{Ll}/u', $word) === 1 ? "o" : "O", $word);

            return $sentenceStart && $word[0] === "0" ? "O" . substr($result, 1) : $result;
        }, $text, true);
    }


    private static function ocrLowercaseL(string $text, ?string $language): string
    {
        $text = self::replaceCallback('/' . self::NOT_IN_WORD . '[\p{L}\'\x{2019}]*l[\p{L}\'\x{2019}]*' . self::WORD_ENDS . '/u',
            fn (array $match): string =>
                preg_match('/(?!l)\p{Ll}/u', $match[0]) !== 1
                && (preg_match_all('/\p{Lu}/u', $match[0]) >= 2 || preg_match('/l\p{Lu}/u', $match[0]) === 1)
                    ? str_replace("l", "I", $match[0])
                    : $match[0],
            $text);

        if (!isset(self::L_CONSONANTS[$language ?? ""])) {
            return $text;
        }

        $start = self::NOT_IN_WORD . '(?<!\p{N} )';
        if ($language === "en") {
            $text   = self::replace('/' . $start . 'l(?=[\'\x{2019}](?:m|ll|ve|d)' . self::WORD_ENDS . ')/u', "I", $text);
            $text   = self::replace('/' . $start . '(?<![^\s"\'(\[\x{00BF}\x{00A1}-])l(?=$|[\s.,!?;:"\')\]\x{2026}-])/u', "I", $text);
            $start .= '(?!l(?:bs?|td)' . self::WORD_ENDS . ')';
        }

        return self::replace('/' . $start . 'l(?=[' . self::L_CONSONANTS[$language] . '])/u', "I", $text);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function doubleSpaces(array $lines): array
    {
        $previousSpace = false;

        return Markup::mapTextRuns($lines, function (string $text, bool $first) use (&$previousSpace): string {
            $text = self::replace('/' . self::SPACES . '{2,}/u', " ", $text);
            if (!$first && $previousSpace) {
                $text = self::replace('/^' . self::SPACES . '+/u', "", $text);
            }
            $previousSpace = preg_match('/' . self::SPACES . '$/u', $text) === 1 || ($previousSpace && $text === "");

            return $text;
        });
    }


    private static function spaceBeforePunctuation(string $text, ?string $language): string
    {
        $end   = '(?:[\s"\'\x{2019}\x{00BB})\]]|[,.!?;:]|$)';
        $marks = $language === "fr" ? "(?:,|\\.(?!\\.))$end" : "(?:(?:[,!?]|\\.(?!\\.))$end|[;:](?:\\s|$))";

        return self::replace('/(?<=[\p{L}\p{N}"\'\x{2019}\x{00BB})\]])' . self::SPACES . "+(?=$marks)/u", "", $text);
    }


    private static function missingSpaceAfterPunctuation(string $text): string
    {
        $text = self::replace('/(?<=\p{L})([,;]|[!?]+)(?=[\p{L}\x{00BF}\x{00A1}])/u', '$1 ', $text);
        $text = self::replace('/(?<=\p{L}\p{L})\.(?=\p{Lu}\p{Ll})/u', ". ", $text);

        return self::replace('/(?<=\p{L})(\.\.\.|\x{2026})(?=\p{L})/u', '$1 ', $text);
    }


    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function replaceList(array $lines, OcrReplaceList $list, bool $continues): array
    {
        $lastLine = count($lines) - 1;
        foreach ($lines as $lineIndex => $line) {
            $lines[$lineIndex] = Markup::mapTextRuns([$line], function (string $text, bool $first, bool $last)
                use ($list, $continues, $lineIndex, $lastLine): string {
                if ($first && $last && isset($list->wholeLines[$text])) {
                    return $list->wholeLines[$text];
                }

                $text = implode(" ", array_map(fn (string $word): string => self::replaceWord($word, $list), explode(" ", $text)));
                if ($first) {
                    $text = self::replaceBeginLines($text, $list->beginLines);
                }
                if ($last && $lineIndex === $lastLine) {
                    foreach ($list->endLines as $from => $to) {
                        $from = (string)$from;
                        if (str_ends_with($text, $from) && !($continues && str_ends_with($to, ".") && !str_ends_with($from, "."))) {
                            $text = substr($text, 0, -strlen($from)) . $to;
                        }
                    }
                }
                foreach ($list->partialLines as $from => $to) {
                    $text = self::replaceBetweenBoundaries($text, (string)$from, $to);
                }
                foreach ($list->partialLinesAlways as $from => $to) {
                    $text = str_replace((string)$from, $to, $text);
                }
                foreach ($list->regularExpressions as $pattern => $replacement) {
                    $text = self::replace((string)$pattern, $replacement, $text);
                }

                return $text;
            })[0];
        }

        return $lines;
    }


    private static function replaceWord(string $word, OcrReplaceList $list): string
    {
        $result = self::lookUpWord($word, $list->wholeWords);
        if ($result !== null) {
            return $result;
        }

        foreach ($list->partialWordsAlways as $from => $to) {
            $word = str_replace((string)$from, $to, $word);
        }

        return self::lookUpWord($word, $list->wholeWords) ?? $word;
    }


    /**
     * Looks up the word with and without the punctuation around it, as GetReplaceWord() of Subtitle Edit does.
     *
     * @param array<string, string> $wholeWords
     */
    private static function lookUpWord(string $word, array $wholeWords): ?string
    {
        preg_match('/^((?:-(?=.))*(?:\.(?=.))*(?:"(?=.))*(?:\((?=.))?)(.*?)((?<=.)["\.,!?)\]:;]*)$/s', $word, $parts);
        [, $pre, $core, $post] = $parts + ["", "", $word, ""];
        if ($core === "") {
            return null;
        }

        return $wholeWords[$pre . $core . $post] ?? (isset($wholeWords[$pre . $core]) ? $wholeWords[$pre . $core] . $post : null)
            ?? (isset($wholeWords[$core . $post]) ? $pre . $wholeWords[$core . $post] : null)
            ?? (isset($wholeWords[$core]) ? $pre . $wholeWords[$core] . $post : null);
    }


    /**
     * @param array<string, string> $beginLines
     */
    private static function replaceBeginLines(string $text, array $beginLines): string
    {
        preg_match('/^[ "\'\[(\x{00B6}' . self::DASHES . ']*/u', $text, $prefix);
        $prefix = $prefix[0] ?? "";
        $rest   = substr($text, strlen($prefix));
        foreach ($beginLines as $from => $to) {
            $from = (string)$from;
            if (str_starts_with($rest, $from)) {
                $rest = $to . substr($rest, strlen($from));
            }
            $rest = str_replace([". $from", "! $from", "? $from"], [". $to", "! $to", "? $to"], $rest);
        }

        return $prefix . $rest;
    }


    /**
     * Replaces $from where it starts and ends at punctuation, whitespace or the text edge, as ReplaceWord() of Subtitle Edit does.
     */
    private static function replaceBetweenBoundaries(string $text, string $from, string $to): string
    {
        $result = "";
        $offset = 0;
        while ($from !== "" && ($position = strpos($text, $from, $offset)) !== false) {
            $end     = $position + strlen($from);
            $startOk = $position === 0 || str_starts_with($from, " ")
                       || preg_match(self::BOUNDARY, self::lastCharacter(substr($text, 0, $position))) === 1;
            $endOk   = $end === strlen($text) || str_ends_with($to, " ")
                       || preg_match(self::BOUNDARY, self::firstCharacter(substr($text, $end))) === 1;
            if ($startOk && $endOk) {
                $result .= substr($text, $offset, $position - $offset) . $to;
                $offset  = $end;
            } else {
                $result .= substr($text, $offset, $position - $offset + 1);
                $offset  = $position + 1;
            }
        }

        return $result . substr($text, $offset);
    }


    private static function lastCharacter(string $text): string
    {
        return preg_match('/.$/su', $text, $match) === 1 ? $match[0] : substr($text, -1);
    }


    private static function firstCharacter(string $text): string
    {
        return preg_match('/^./su', $text, $match) === 1 ? $match[0] : substr($text, 0, 1);
    }


    /**
     * Returns $text unchanged when it is not valid UTF-8.
     */
    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }


    private static function replaceCallback(string $pattern, callable $fn, string $text, bool $offsets = false): string
    {
        return preg_replace_callback($pattern, $fn, $text, -1, $count, $offsets ? PREG_OFFSET_CAPTURE : 0) ?? $text;
    }
}
