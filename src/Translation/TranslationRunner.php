<?php

declare(strict_types=1);

namespace SubtitleToolbox\Translation;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class TranslationRunner
{
    private const TAG_REGEX                = '/(<\/?[a-zA-Z][^<>]*>|<\d{2,}:[0-5]\d:[0-5]\d\.\d{3}>)/';
    private const PLACEHOLDER_REGEX        = '/(<\/?x\d+\/?>)/';
    private const LOOSE_PLACEHOLDER_REGEX  = '/<\s*\/?\s*x\s*\d+\s*\/?\s*>/i';
    private const SENTENCE_END_REGEX       = '/[.?!\x{2026}\x{3002}\x{FF0E}\x{FF1F}\x{FF01}]["\'\x{201D}\x{2019}\x{00BB})\]]*$/u';
    private const NOT_TRANSLATED_REGEX     = '/^[\p{N}\p{P}\p{S}\s]*$/u';
    private const NO_SPACES_SCRIPT_REGEX   = '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]{2}$/u';

    /** @var list<TranslationWarning> */
    private array $warnings = [];


    public function __construct(private readonly TranslationEngine $engine)
    {
    }


    /**
     * Returns a copy of $subtitle with the text of each cue translated by the engine and the language metadata set to $target.
     */
    public function translate(Subtitle $subtitle, string $source, string $target, ?TranslationOptions $options = null): Subtitle
    {
        $options        = $options ?? new TranslationOptions();
        $this->warnings = [];

        // slice() over all time is the public way to copy the cues and keep the comments.
        $copy = $subtitle->slice(-INF, INF);
        $cues = $copy->getCues();

        $requests = [];
        foreach ($this->groupCues($cues, $options) as $cueIndexes) {
            $requests[] = ["cueIndexes" => $cueIndexes] + $this->encode(array_map(fn (int $index): SubtitleCue => $cues[$index], $cueIndexes));
        }

        foreach ($this->batches($requests, $options->maxCharactersPerRequest) as $batch) {
            $texts        = array_column($batch, "text");
            $translations = $this->engine->translate($texts, $source, $target);
            if (!array_is_list($translations) || count($translations) !== count($texts) ||
                count(array_filter($translations, "is_string")) !== count($texts)) {
                throw new InvalidArgumentException("The translation engine must return one string per text, " .
                                                   "got " . count($translations) . " values for " . count($texts) . " texts.");
            }

            foreach ($batch as $requestIndex => $request) {
                $this->apply($cues, $request["cueIndexes"], $request["tags"], $translations[$requestIndex]);
            }
        }

        usort($this->warnings, fn (TranslationWarning $warning1, TranslationWarning $warning2): int => $warning1->cueIndex <=> $warning2->cueIndex);

        return $copy->setMetadata(Subtitle::METADATA_LANGUAGE, $target);
    }


    /**
     * Returns the warnings of the last translate() call, ordered by cue index.
     *
     * @return list<TranslationWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }


    /**
     * @param array<int, SubtitleCue> $cues
     * @return list<list<int>>
     */
    private function groupCues(array $cues, TranslationOptions $options): array
    {
        $groups   = [];
        $cueIndex = 0;
        while ($cueIndex < count($cues)) {
            if (!$this->needsTranslation($cues[$cueIndex])) {
                $cueIndex++;
                continue;
            }

            $group = [$cueIndex];
            while ($options->joinSentences && count($group) < $options->maxCuesPerSentence && !$this->endsSentence($cues[$cueIndex])
                   && isset($cues[$cueIndex + 1]) && $this->needsTranslation($cues[$cueIndex + 1])) {
                $group[] = ++$cueIndex;
            }

            $groups[] = $group;
            $cueIndex++;
        }

        return $groups;
    }


    private function needsTranslation(SubtitleCue $cue): bool
    {
        $text = self::visibleText($cue);

        return $text !== "" && preg_match(self::NOT_TRANSLATED_REGEX, $text) !== 1;
    }


    private function endsSentence(SubtitleCue $cue): bool
    {
        // Invalid UTF-8 makes preg_match() return false. Such a cue ends the sentence, so the runner never cuts it.
        return preg_match(self::SENTENCE_END_REGEX, self::visibleText($cue)) !== 0;
    }


    private static function visibleText(SubtitleCue $cue): string
    {
        return trim(Markup::plainText(implode(" ", $cue->getLines())));
    }


    /**
     * Replaces the tags with numbered placeholders, and returns the text for the engine and the tags by placeholder number.
     *
     * @param list<SubtitleCue> $cues
     * @return array{text: string, tags: array<int, array{0: string, 1: ?string}>}
     */
    private function encode(array $cues): array
    {
        $text = count($cues) === 1
            ? implode("\n", $cues[0]->getLines())
            : implode(" ", array_map(fn (SubtitleCue $cue): string => implode(" ", $cue->getLines()), $cues));

        $parts      = preg_split(self::TAG_REGEX, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $closeIndex = [];
        $openByName = [];
        foreach ($parts as $partIndex => $part) {
            if ($partIndex % 2 === 0 || !preg_match('/^<(\/?)([a-zA-Z][^\s\/>]*)/', $part, $match) || str_ends_with($part, "/>")) {
                continue;
            }

            $name = strtolower($match[2]);
            if ($match[1] === "") {
                $openByName[$name][] = $partIndex;
            } elseif (!empty($openByName[$name])) {
                $closeIndex[array_pop($openByName[$name])] = $partIndex;
            }
        }

        $tags        = [];
        $placeholder = [];
        foreach ($parts as $partIndex => $part) {
            if ($partIndex % 2 === 0) {
                $placeholder[$partIndex] = Markup::escapeText(Markup::decodeEntities($part));
            } elseif (isset($closeIndex[$partIndex])) {
                $number                               = count($tags) + 1;
                $tags[$number]                        = [$part, $parts[$closeIndex[$partIndex]]];
                $placeholder[$partIndex]              = "<x$number>";
                $placeholder[$closeIndex[$partIndex]] = "</x$number>";
            } elseif (!isset($placeholder[$partIndex])) {
                $number                  = count($tags) + 1;
                $tags[$number]           = [$part, null];
                $placeholder[$partIndex] = "<x$number/>";
            }
        }
        ksort($placeholder);

        return ["text" => implode("", $placeholder), "tags" => $tags];
    }


    /**
     * Fills each batch with whole texts up to $maxCharacters. A longer text goes out alone.
     *
     * @param list<array{cueIndexes: list<int>, text: string, tags: array}> $requests
     * @return list<list<array{cueIndexes: list<int>, text: string, tags: array}>>
     */
    private function batches(array $requests, int $maxCharacters): array
    {
        $batches    = [];
        $batch      = [];
        $characters = 0;
        foreach ($requests as $request) {
            $length = Markup::countCharacters($request["text"]);
            if ($batch !== [] && $characters + $length > $maxCharacters) {
                $batches[]  = $batch;
                $batch      = [];
                $characters = 0;
            }
            $batch[]     = $request;
            $characters += $length;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }


    /**
     * @param array<int, SubtitleCue>                     $cues
     * @param list<int>                                   $cueIndexes
     * @param array<int, array{0: string, 1: ?string}>    $tags
     */
    private function apply(array $cues, array $cueIndexes, array $tags, string $translation): void
    {
        if (!$this->keepsPlaceholders($translation, $tags)) {
            $translation = preg_replace(self::LOOSE_PLACEHOLDER_REGEX, "", $translation);
            $tags        = [];
            foreach ($cueIndexes as $cueIndex) {
                $this->warnings[] = new TranslationWarning($cueIndex, "The engine dropped or changed a placeholder tag. " .
                                                                      "The cue has no tags.");
            }
        }

        $tokens = $this->tokenize($translation);
        if (count($cueIndexes) === 1) {
            $cues[$cueIndexes[0]]->setLines(explode("\n", self::restore($tokens, $tags)));

            return;
        }

        $weights = array_map(fn (int $cueIndex): int => max(1, Markup::countCharacters(self::visibleText($cues[$cueIndex]))), $cueIndexes);
        foreach ($this->split($tokens, $weights) as $pieceIndex => $piece) {
            $cueIndex = $cueIndexes[$pieceIndex];
            $cues[$cueIndex]->setLines(str_replace("\n", " ", self::restore($piece, $tags)));
            if ($cues[$cueIndex]->getLines() === []) {
                $this->warnings[] = new TranslationWarning($cueIndex, "The translation of the sentence is too short to fill " .
                                                                      "this cue. The cue has no text.");
            }
        }
    }


    /**
     * @param array<int, array{0: string, 1: ?string}> $tags
     */
    private function keepsPlaceholders(string $translation, array $tags): bool
    {
        $expected = [];
        foreach ($tags as $number => [, $close]) {
            array_push($expected, ...($close === null ? ["<x$number/>"] : ["<x$number>", "</x$number>"]));
        }

        preg_match_all(self::PLACEHOLDER_REGEX, $translation, $matches);
        $found = $matches[0];
        if (preg_match_all(self::LOOSE_PLACEHOLDER_REGEX, $translation) !== count($found)) {
            return false;
        }

        sort($expected);
        sort($found);
        if ($expected !== $found) {
            return false;
        }

        foreach ($tags as $number => [, $close]) {
            if ($close !== null && strpos($translation, "<x$number>") > strpos($translation, "</x$number>")) {
                return false;
            }
        }

        return true;
    }


    /**
     * Splits the translation into placeholder tokens such as ["open", 1] and text tokens ["text", "decoded text"].
     *
     * @return list<array{0: string, 1: int|string}>
     */
    private function tokenize(string $translation): array
    {
        $tokens = [];
        foreach (preg_split(self::PLACEHOLDER_REGEX, $translation, -1, PREG_SPLIT_DELIM_CAPTURE) as $partIndex => $part) {
            if ($partIndex % 2 === 0) {
                if ($part !== "") {
                    $tokens[] = ["text", Markup::decodeEntities($part)];
                }
            } else {
                preg_match('/^<(\/?)x(\d+)(\/?)>$/', $part, $match);
                $tokens[] = [$match[1] === "/" ? "close" : ($match[3] === "/" ? "single" : "open"), (int) $match[2]];
            }
        }

        return $tokens;
    }


    /**
     * Cuts the tokens into one piece per weight, in proportion to the weights, at a space or between two characters of a
     * script without spaces, such as Chinese.
     * Each piece closes the tags that are open at its end, and the next piece opens them again.
     *
     * @param list<array{0: string, 1: int|string}> $tokens
     * @param list<int>                             $weights
     * @return list<list<array{0: string, 1: int|string}>>
     */
    private function split(array $tokens, array $weights): array
    {
        $characters = [];
        foreach ($tokens as [$type, $value]) {
            if ($type === "text") {
                array_push($characters, ...Markup::characters($value));
            }
        }

        $cuts   = self::cutPositions($characters, $weights);
        $pieces = array_fill(0, count($weights), []);
        $piece  = 0;
        $offset = 0;
        foreach ($tokens as [$type, $value]) {
            if ($type === "close") {
                $pieces[$piece][] = [$type, $value];
                continue;
            }

            $parts = $type === "text" ? Markup::characters($value) : [null];
            foreach ($parts as $character) {
                while (isset($cuts[$piece]) && $offset >= $cuts[$piece]) {
                    $piece++;
                }
                if ($character === null) {
                    $pieces[$piece][] = [$type, $value];
                    continue;
                }
                if ($piece > 0 && $offset === $cuts[$piece - 1] && trim($character) === "") {
                    $offset++;
                    continue;
                }

                $last = array_key_last($pieces[$piece]);
                if ($last !== null && $pieces[$piece][$last][0] === "text") {
                    $pieces[$piece][$last][1] .= $character;
                } else {
                    $pieces[$piece][] = ["text", $character];
                }
                $offset++;
            }
        }

        $open = [];
        foreach ($pieces as $pieceIndex => $pieceTokens) {
            $balanced = array_map(fn (int $number): array => ["open", $number], $open);
            foreach ($pieceTokens as [$type, $value]) {
                if ($type === "open") {
                    $open[] = $value;
                } elseif ($type === "close" && in_array($value, $open, true)) {
                    unset($open[array_search($value, $open, true)]);
                    $open = array_values($open);
                }
                $balanced[] = [$type, $value];
            }
            foreach (array_reverse($open) as $number) {
                $balanced[] = ["close", $number];
            }
            $pieces[$pieceIndex] = $balanced;
        }

        return $pieces;
    }


    /**
     * Returns the character offsets where pieces 2 to n start, each as close as the cuts allow to its share of the weights.
     *
     * @param list<string> $characters
     * @param list<int>    $weights
     * @return list<int>
     */
    private static function cutPositions(array $characters, array $weights): array
    {
        $candidates = [];
        foreach ($characters as $offset => $character) {
            if ($offset === 0) {
                continue;
            }
            if (trim($character) === "" ? $offset < count($characters) - 1
                : preg_match(self::NO_SPACES_SCRIPT_REGEX, $characters[$offset - 1] . $character) === 1) {
                $candidates[] = $offset;
            }
        }

        $cutCount = min(count($weights) - 1, count($candidates));
        $total    = array_sum($weights);
        $cuts     = [];
        $share    = 0;
        $previous = -1;
        for ($cut = 0; $cut < $cutCount; $cut++) {
            $share += $weights[$cut];
            $target = count($characters) * $share / $total;
            $best   = $previous + 1;
            for ($candidate = $best + 1; $candidate < count($candidates) - ($cutCount - 1 - $cut); $candidate++) {
                if (abs($candidates[$candidate] - $target) < abs($candidates[$best] - $target)) {
                    $best = $candidate;
                }
            }
            $cuts[]   = $candidates[$best];
            $previous = $best;
        }

        return $cuts;
    }


    /**
     * @param list<array{0: string, 1: int|string}>    $tokens
     * @param array<int, array{0: string, 1: ?string}> $tags
     */
    private static function restore(array $tokens, array $tags): string
    {
        $text = "";
        foreach ($tokens as [$type, $value]) {
            $text .= match ($type) {
                "text"  => Markup::escapeText($value),
                "close" => "</x$value>",
                "open"  => "<x$value>",
                default => "<x$value/>",
            };
        }

        do {
            $text = preg_replace('/<x(\d+)>(\s*)<\/x\1>/', '$2', $text, -1, $count);
        } while ($count > 0);

        return preg_replace_callback(self::PLACEHOLDER_REGEX, function (array $match) use ($tags): string {
            preg_match('/^<(\/?)x(\d+)/', $match[1], $parts);

            return $parts[1] === "/" ? (string) $tags[(int) $parts[2]][1] : $tags[(int) $parts[2]][0];
        }, $text);
    }
}
