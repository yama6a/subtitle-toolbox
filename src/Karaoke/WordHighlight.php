<?php

namespace SubtitleToolbox\Karaoke;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class WordHighlight
{
    /**
     * Returns a new subtitle with one cue per timed word, in which the style marks the active word.
     */
    public static function expand(Subtitle $subtitle, WordHighlightOptions $options): Subtitle
    {
        $groups = [];
        foreach ($subtitle->getCues() as $index => $cue) {
            $groups[$index] = self::expandCue($cue, $options);
        }

        $cues = array_merge([], ...array_values($groups));
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        // A slice that keeps no cue is a copy of the metadata and format data without cues and comments.
        $result = $subtitle->slice(INF, INF);
        foreach ($cues as $cue) {
            $result->addCue($cue, false);
        }
        foreach ($subtitle->getComments() as $comment) {
            $result->addComment($comment["text"], self::findNewIndex($groups, $cues, $comment["beforeCueIndex"]));
        }

        return $result;
    }


    /**
     * @return list<SubtitleCue>
     */
    private static function expandCue(SubtitleCue $cue, WordHighlightOptions $options): array
    {
        [$lines, $times] = self::splitWords($cue);
        if ($times === []) {
            return [clone $cue];
        }

        $start  = $cue->getStart();
        $end    = max($start, $cue->getEnd());
        $bounds = [$start];
        foreach ($times as $time) {
            $bounds[] = max(end($bounds), min($end, $time));
        }
        $bounds[] = $end;

        $cues = [];
        // Word -1 is the time from the cue start to the first word timestamp. Its cue styles no word.
        for ($word = -1; $word < count($times); $word++) {
            $wordStart = $bounds[$word + 1];
            $wordEnd   = $bounds[$word + 2];
            if (round($wordEnd - $wordStart, 3) <= 0) {
                continue;
            }

            $cues[] = (clone $cue)
                ->setStart($wordStart)
                ->setEnd($wordEnd)
                ->setLinesByArray(self::highlight($lines, $word, count($times), $options))
                ->setIdentifier($cues === [] ? $cue->getIdentifier() : null);
        }

        if ($cues === []) {
            return [(clone $cue)->setLinesByArray(self::highlight($lines, -1, count($times), $options))];
        }

        return $cues;
    }


    /**
     * Splits the cue lines into tags and text runs. Each text run carries the number of its word, -1 before the first.
     *
     * @return array{list<list<array{string, string, int}>>, list<float>} the lines of ["tag" or "text", value, word], and the word times
     */
    private static function splitWords(SubtitleCue $cue): array
    {
        $lines = [];
        $times = [];
        foreach ($cue->getLines() as $line) {
            $items  = [];
            $tokens = preg_split('/(<[^>]*>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $token) {
                $time = Markup::wordTimestampSeconds($token);
                if ($time !== null) {
                    $times[] = $time;
                } elseif (str_starts_with($token, "<")) {
                    $items[] = ["tag", $token, count($times) - 1];
                } else {
                    $items[] = ["text", $token, count($times) - 1];
                }
            }
            $lines[] = $items;
        }

        return [$lines, $times];
    }


    /**
     * Writes the lines with the style around the active words and without the words outside the window.
     *
     * @param list<list<array{string, string, int}>> $lines
     *
     * @return list<string>
     */
    private static function highlight(array $lines, int $active, int $wordCount, WordHighlightOptions $options): array
    {
        [$first, $last] = self::window($active, $wordCount, $options->maxWordsPerCue);
        $openTag        = "<" . trim($options->style) . ">";
        $closeTag       = "</" . $options->getTagName() . ">";

        $result = [];
        foreach ($lines as $items) {
            $output  = "";
            $isOpen  = false;
            $pending = "";
            foreach ($items as [$type, $value, $word]) {
                $isVisible = ($word >= $first || ($word === -1 && $first === 0)) && $word <= $last;
                $isStyled  = $word >= 0 && ($options->mode === WordHighlightOptions::MODE_WORD ? $word === $active : $word <= $active);

                if ($type === "text" && !$isVisible) {
                    continue;
                }

                if ($type === "tag" || !$isStyled) {
                    // The style closes before every tag, so that it never crosses the nesting of the other tags.
                    $output .= ($isOpen ? $closeTag : "") . $pending . $value;
                    $isOpen  = false;
                    $pending = "";
                    continue;
                }

                preg_match('/^(\s*)(.*?)(\s*)$/s', $value, $parts);
                if ($parts[2] === "") {
                    $pending .= $value;
                    continue;
                }

                $output .= $pending . $parts[1] . ($isOpen ? "" : $openTag) . $parts[2];
                $isOpen  = true;
                $pending = $parts[3];
            }
            $result[] = $output . ($isOpen ? $closeTag : "") . $pending;
        }

        if ($options->maxWordsPerCue === null) {
            return $result;
        }

        return array_values(array_filter(
            array_map(self::removeEmptyTagPairs(...), $result),
            fn (string $line): bool => trim(Markup::stripAllTags($line)) !== ""
        ));
    }


    /**
     * Returns the first and last word number that the cue shows, centred on the active word.
     *
     * @return array{int, int}
     */
    private static function window(int $active, int $wordCount, ?int $maxWords): array
    {
        if ($maxWords === null || $maxWords >= $wordCount) {
            return [-1, $wordCount - 1];
        }

        $first = max(0, min(max(0, $active) - intdiv($maxWords - 1, 2), $wordCount - $maxWords));

        return [$first, $first + $maxWords - 1];
    }


    /**
     * Removes the tag pairs and the spaces at the line edges that the hidden words leave behind.
     */
    private static function removeEmptyTagPairs(string $line): string
    {
        do {
            $line = preg_replace('/<(b|i|u|s|font)\b[^>]*>(\s*)<\/\1>/', '$2', $line, -1, $count);
        } while ($count > 0);

        return preg_replace(['/^((?:<[^\/][^>]*>)*)\s+/', '/\s+((?:<\/[^>]*>)*)$/'], '$1', $line);
    }


    /**
     * Returns the new index of the first cue that comes from the original cue at or after $beforeCueIndex.
     *
     * @param array<int, list<SubtitleCue>> $groups original cue index => its new cues
     * @param list<SubtitleCue> $cues
     */
    private static function findNewIndex(array $groups, array $cues, int $beforeCueIndex): int
    {
        foreach ($groups as $index => $group) {
            if ($index >= $beforeCueIndex) {
                return array_search($group[0], $cues, true);
            }
        }

        return count($cues);
    }
}
