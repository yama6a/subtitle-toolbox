<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\CueList;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\LineWrapper;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class Resegmenter
{
    private const CLOSERS      = '["\'\)\]\x{2019}\x{201D}\x{3009}\x{300B}\x{300D}\x{300F}\x{3011}\x{FF09}\x{FF3D}\x{FF5D}]*';
    private const CJK_BREAKS   = '[\x{3001}\x{3002}\x{FF01}\x{FF0C}\x{FF1A}\x{FF1B}\x{FF1F}\x{FF61}\x{FF64}]';
    private const SENTENCE_END = '[.?!\x{2026}\x{3002}\x{FF01}\x{FF1F}\x{FF61}]+';
    private const CLAUSE_END   = '(?:[,;:\x{2013}\x{2014}\x{3001}\x{FF0C}\x{FF1A}\x{FF1B}\x{FF64}]|--|^-)';


    /**
     * Splits long cues or builds new cues from the word timestamps, as the mode of $options selects.
     */
    public static function apply(Subtitle $subtitle, ResegmentOptions $options): ResegmentReport
    {
        $cuesBefore = count($subtitle->getCues());
        match ($options->mode) {
            ResegmentMode::SplitLong => self::splitLong($subtitle, $options),
            ResegmentMode::ByWords   => self::byWords($subtitle, $options),
        };

        return new ResegmentReport($cuesBefore, count($subtitle->getCues()));
    }


    /**
     * Splits each cue that breaks a limit of $options at sentence ends, then at clause ends, then at the space closest to the middle.
     */
    private static function splitLong(Subtitle $subtitle, ResegmentOptions $options): void
    {
        $anchors = CommentAnchors::of($subtitle->getCues(), $subtitle->getComments());
        $cues    = [];
        foreach ($subtitle->getCues() as $cue) {
            $cues = [...$cues, ...self::splitCue($cue, $options)];
        }

        $subtitle->replaceCues($cues, $anchors);
    }


    /**
     * Drops the cue boundaries and builds new cues from the word timestamps, one sentence per cue within the limits of $options.
     */
    private static function byWords(Subtitle $subtitle, ResegmentOptions $options): void
    {
        $anchors = CommentAnchors::of($subtitle->getCues(), $subtitle->getComments());
        $newCues = new \SplObjectStorage();
        $result  = [];
        $group   = [];
        foreach (CueList::inStartOrder($subtitle->getCues()) as $cue) {
            $words = CueImage::isImageCue($cue) ? [] : self::words($cue);
            if ($words === []) {
                $result        = [...$result, ...self::flush($group, $newCues, $options)];
                $group         = [];
                $result[]      = $cue;
                $newCues[$cue] = $cue;
                continue;
            }

            foreach ($words as $word) {
                $last = end($group) ?: null;
                if ($last !== null && (!self::sameSource($last["cue"], $cue)
                    || round($word["start"] - $last["end"], 3) >= round($options->maxWordGap, 3)
                    || !self::groupFits([...$group, $word], $options))) {
                    $result = [...$result, ...self::flush($group, $newCues, $options)];
                    $group  = [];
                }

                $group[] = $word;
                if ($word["endsSentence"]) {
                    $result = [...$result, ...self::flush($group, $newCues, $options)];
                    $group  = [];
                }
            }
        }
        $result = [...$result, ...self::flush($group, $newCues, $options)];

        $subtitle->replaceCues(
            $result,
            array_map(fn (?SubtitleCue $anchor): ?SubtitleCue => $anchor === null ? null : $newCues[$anchor], $anchors)
        );
    }


    /**
     * @return list<SubtitleCue> $cue itself first, then the new cues
     */
    private static function splitCue(SubtitleCue $cue, ResegmentOptions $options): array
    {
        $pieces = CueImage::isImageCue($cue) ? [] : self::pieces($cue);
        if ($pieces === []) {
            return [$cue];
        }

        $positions = self::positions($pieces);
        $times     = self::times($pieces, $positions, $cue->getStart(), $cue->getEnd());
        $breaks    = self::findBreaks($pieces, $positions, $times, 0, count($pieces), $options);
        if ($breaks === []) {
            return [$cue];
        }

        $parts = [];
        foreach ([0, ...$breaks] as $partIndex => $first) {
            $parts[] = [$partIndex === 0 ? $cue : (clone $cue)->setIdentifier(null), $first, $breaks[$partIndex] ?? count($pieces)];
        }
        foreach ($parts as [$part, $first, $end]) {
            $lines = explode("\n", self::text($pieces, $first, $end));
            $part->setStart($times[$first])->setEnd($times[$end])->setLinesByArray(self::wrap($lines, $options));
        }

        return array_column($parts, 0);
    }


    /**
     * Returns the indexes of the pieces that start a new cue, best break point first, until each part fits.
     *
     * @param list<array> $pieces
     * @param list<int>   $positions
     * @param list<float> $times
     *
     * @return list<int>
     */
    private static function findBreaks(
        array $pieces,
        array $positions,
        array $times,
        int $first,
        int $end,
        ResegmentOptions $options
    ): array
    {
        $lines = explode("\n", self::text($pieces, $first, $end));
        if (self::fits($lines, $times[$first], $times[$end], $options)) {
            return [];
        }

        $best = null;
        for ($index = $first + 1; $index < $end; $index++) {
            $rank = self::breakRank($pieces, $index);
            if ($rank === null
                || round($times[$index] - $times[$first], 3) < round($options->minDuration, 3)
                || round($times[$end] - $times[$index], 3) < round($options->minDuration, 3)) {
                continue;
            }

            $candidate = [$rank, abs(2 * $positions[$index] - $positions[$first] - $positions[$end]), $index];
            if ($best === null || $candidate < $best) {
                $best = $candidate;
            }
        }
        if ($best === null) {
            return [];
        }

        return [
            ...self::findBreaks($pieces, $positions, $times, $first, $best[2], $options),
            $best[2],
            ...self::findBreaks($pieces, $positions, $times, $best[2], $end, $options),
        ];
    }


    /**
     * Returns 0 for a sentence end before piece $index, 1 for a clause end, 2 for another word boundary, or null.
     *
     * @param list<array> $pieces
     */
    private static function breakRank(array $pieces, int $index): ?int
    {
        if (self::endsSentence($pieces, $index - 1)) {
            return 0;
        }

        $before = Markup::plainText($pieces[$index - 1]["text"]);
        if (preg_match('/' . self::CLAUSE_END . self::CLOSERS . '$/u', $before) === 1) {
            return 1;
        }

        return $pieces[$index]["separator"] !== "" || $pieces[$index]["time"] !== null ? 2 : null;
    }


    /**
     * A full stop before a word in lower case, as in "e.g. this", ends no sentence.
     *
     * @param list<array> $pieces
     */
    private static function endsSentence(array $pieces, int $index): bool
    {
        $text = Markup::plainText($pieces[$index]["text"]);
        if (preg_match('/' . self::SENTENCE_END . self::CLOSERS . '$/u', $text) !== 1) {
            return false;
        }

        $next = isset($pieces[$index + 1]) ? Markup::plainText($pieces[$index + 1]["text"]) : "";

        return preg_match('/^\p{Ll}/u', $next) !== 1;
    }


    /**
     * @param list<string> $lines
     */
    private static function fits(array $lines, float $start, float $end, ResegmentOptions $options): bool
    {
        $duration = round($end - $start, 3);
        if ($duration > round($options->maxDuration, 3)
            || LineWrapper::wrapToFit($lines, $options->maxCharactersPerLine, $options->maxLines) === null) {
            return false;
        }
        if ($options->maxCharactersPerSecond === null) {
            return true;
        }

        $characters = LineWrapper::visibleCharacters($lines);

        return $characters === 0 || ($duration > 0 ? $characters / $duration : INF) <= $options->maxCharactersPerSecond;
    }


    /**
     * Wraps the lines as mergeShortCues() does, or as wrapLines() does when they do not fit.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function wrap(array $lines, ResegmentOptions $options): array
    {
        return LineWrapper::wrapToFit($lines, $options->maxCharactersPerLine, $options->maxLines)
            ?? LineWrapper::wrap($lines, $options->maxCharactersPerLine, $options->maxLines);
    }


    /**
     * Splits the cue text into pieces at spaces, at line breaks, after CJK punctuation and before word timestamps inside a word.
     *
     * @return list<array{text: string, length: int, separator: string, time: ?float, openBefore: list<array>, openAfter: list<array>}>
     */
    private static function pieces(SubtitleCue $cue): array
    {
        $pieces = [];
        $prefix = "";
        foreach ($cue->getLines() as $line) {
            foreach (LineWrapper::measuredWords($line) as $wordIndex => $word) {
                $separator = $wordIndex > 0 ? " " : ($pieces === [] ? "" : "\n");
                foreach (self::splitWord($word["text"]) as $partIndex => $text) {
                    $piece = ["text"      => $text,
                              "length"    => LineWrapper::length(LineWrapper::measuredWords($text)),
                              "separator" => $partIndex === 0 ? $separator : ""];

                    // A word of tags only, such as "</i>" after a space, joins its neighbour, so that no cue holds only tags.
                    if ($piece["length"] === 0 && $pieces !== []) {
                        $pieces[count($pieces) - 1]["text"] .= $piece["separator"] . $text;
                    } elseif ($piece["length"] === 0) {
                        $prefix .= "$text ";
                    } else {
                        $piece["text"] = $prefix . $piece["text"];
                        $prefix        = "";
                        $pieces[]      = $piece;
                    }
                }
            }
        }

        $openTags = [];
        foreach ($pieces as $index => $piece) {
            $pieces[$index]["openBefore"] = $openTags;
            $openTags                     = Markup::openCoreTags($piece["text"], $openTags);
            $pieces[$index]["openAfter"]  = $openTags;
            $pieces[$index]["time"]       = null;
            if (preg_match('/^(?:<[^>\d][^>]*>)*(<\d{2,}:[0-5]\d:[0-5]\d\.\d{3}>)/', $piece["text"], $matches) === 1) {
                $pieces[$index]["time"] = round(Markup::wordTimestampSeconds($matches[1]), 3);
            }
        }

        return $pieces;
    }


    /**
     * @return list<string>
     */
    private static function splitWord(string $word): array
    {
        $parts        = [""];
        $hasText      = false;
        $isAfterBreak = false;
        foreach (preg_split('/(<[^>]*>)/', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^<[^>]*>$/', $token) === 1) {
                $isTimestamp = preg_match(Markup::WORD_TIMESTAMP_REGEX, $token) === 1;
                if ($hasText && (($isAfterBreak && !str_starts_with($token, "</")) || $isTimestamp)) {
                    $parts[]      = "";
                    $hasText      = false;
                    $isAfterBreak = false;
                }
                $parts[count($parts) - 1] .= $token;
                continue;
            }

            $chunks = preg_match_all('/.*?' . self::CJK_BREAKS . '+' . self::CLOSERS . '|.+/su', $token, $matches)
                ? $matches[0]
                : [$token];
            foreach ($chunks as $chunk) {
                if ($hasText && $isAfterBreak) {
                    $parts[] = "";
                }
                $parts[count($parts) - 1] .= $chunk;
                $hasText      = true;
                $isAfterBreak = preg_match('/' . self::CJK_BREAKS . self::CLOSERS . '$/u', $chunk) === 1;
            }
        }

        return $parts;
    }


    /**
     * Joins the pieces from $first to $end - 1, opens the core markup tags that are open before them and closes the tags open after them.
     *
     * @param list<array> $pieces
     */
    private static function text(array $pieces, int $first, int $end): string
    {
        $text = implode("", array_column($pieces[$first]["openBefore"], "tag"));
        for ($index = $first; $index < $end; $index++) {
            $text .= ($index > $first ? $pieces[$index]["separator"] : "") . $pieces[$index]["text"];
        }

        if ($end < count($pieces)) {
            $text .= Markup::closeCoreTags($pieces[$end - 1]["openAfter"]);
        }

        return $text;
    }


    /**
     * @param list<array> $pieces
     *
     * @return list<int> the visible characters before each piece, then the visible characters of the whole text
     */
    private static function positions(array $pieces): array
    {
        $positions = [0];
        foreach ($pieces as $index => $piece) {
            $separator   = isset($pieces[$index + 1]) && $pieces[$index + 1]["separator"] !== "" ? 1 : 0;
            $positions[] = end($positions) + $piece["length"] + $separator;
        }

        return $positions;
    }


    /**
     * Returns the start time of each piece, then $end. A word timestamp sets the time of its piece.
     * Other times split in proportion to the visible characters.
     *
     * @param list<array> $pieces
     * @param list<int>   $positions
     *
     * @return list<float>
     */
    private static function times(array $pieces, array $positions, float $start, float $end): array
    {
        $anchors = [0 => $start];
        foreach ($pieces as $index => $piece) {
            if ($index > 0 && $piece["time"] !== null && $piece["time"] > end($anchors) && $piece["time"] < $end) {
                $anchors[$index] = $piece["time"];
            }
        }
        $anchors[count($pieces)] = max($start, $end);

        $times   = [];
        $indexes = array_keys($anchors);
        foreach (array_slice($indexes, 1) as $number => $next) {
            $previous = $indexes[$number];
            $span     = $positions[$next] - $positions[$previous];
            for ($index = $previous; $index < $next; $index++) {
                $share         = $span > 0 ? ($positions[$index] - $positions[$previous]) / $span : 0;
                $times[$index] = round($anchors[$previous] + ($anchors[$next] - $anchors[$previous]) * $share, 3);
            }
        }
        $times[count($pieces)] = $anchors[count($pieces)];

        return $times;
    }


    /**
     * Returns the words of the cue with their times, or [] when the cue has no word timestamp.
     *
     * @return list<array{cue: SubtitleCue, pieces: list<array>, index: int, start: float, end: float, endsSentence: bool}>
     */
    private static function words(SubtitleCue $cue): array
    {
        $pieces = self::pieces($cue);
        if (array_filter(array_column($pieces, "time"), fn (?float $time): bool => $time !== null) === []) {
            return [];
        }

        $starts   = [];
        $previous = $cue->getStart();
        foreach ($pieces as $index => $piece) {
            $time           = $piece["time"];
            $previous       = $time !== null && $time >= $previous && $time <= $cue->getEnd() ? $time : $previous;
            $starts[$index] = $previous;
        }

        $words = [];
        foreach ($pieces as $index => $piece) {
            $words[] = [
                "cue"          => $cue,
                "pieces"       => $pieces,
                "index"        => $index,
                "start"        => $starts[$index],
                "end"          => $starts[$index + 1] ?? max($cue->getEnd(), $starts[$index]),
                "endsSentence" => self::endsSentence($pieces, $index),
            ];
        }

        return $words;
    }


    private static function sameSource(SubtitleCue $first, SubtitleCue $second): bool
    {
        return $first === $second
            || (($first->getAlignment() ?? 2) === ($second->getAlignment() ?? 2)
                && $first->isForced() === $second->isForced()
                && CueList::speakers($first) === CueList::speakers($second));
    }


    /**
     * @param list<array> $group
     *
     * @return list<string>
     */
    private static function groupLines(array $group): array
    {
        $texts = [];
        $first = 0;
        foreach ($group as $number => $word) {
            $next = $group[$number + 1] ?? null;
            if ($next === null || $next["cue"] !== $word["cue"]) {
                $texts[] = self::text($word["pieces"], $group[$first]["index"], $word["index"] + 1);
                $first   = $number + 1;
            }
        }

        return explode("\n", implode("\n", $texts));
    }


    /**
     * @param list<array> $group
     */
    private static function groupFits(array $group, ResegmentOptions $options): bool
    {
        return self::fits(self::groupLines($group), $group[0]["start"], end($group)["end"], $options);
    }


    /**
     * Builds one cue from the words of $group and records it in $newCues for each cue whose first word it holds.
     *
     * @param list<array>                                 $group
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $newCues
     *
     * @return list<SubtitleCue>
     */
    private static function flush(array $group, \SplObjectStorage $newCues, ResegmentOptions $options): array
    {
        if ($group === []) {
            return [];
        }

        $first = $group[0];
        $cue   = (clone $first["cue"])
            ->setIdentifier($first["index"] === 0 ? $first["cue"]->getIdentifier() : null)
            ->setStart($first["start"])
            ->setEnd(end($group)["end"])
            ->setLinesByArray(self::wrap(self::groupLines($group), $options));

        foreach ($group as $word) {
            if ($word["index"] === 0) {
                $newCues[$word["cue"]] = $cue;
            }
        }

        return [$cue];
    }
}
