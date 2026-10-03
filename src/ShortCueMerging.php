<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Image\CueImage;

trait ShortCueMerging
{
    /**
     * Joins each short cue with the next cue, or else with the previous cue, when the joined cue fits the limits of $options.
     */
    public function mergeShortCues(MergeShortCuesOptions $options): self
    {
        $anchors = $this->getCommentAnchors();
        $cues    = $this->fixesCuesInStartOrder();
        $index   = 0;
        while ($index < count($cues)) {
            if (!$options->sameSpeakerOnly && !self::shortCueMergingIsShort($cues[$index], $options)) {
                $index++;
                continue;
            }

            $lines = null;
            foreach ([$index, $index - 1] as $first) {
                if (isset($cues[$first], $cues[$first + 1])) {
                    $lines = self::shortCueMergingJoinLines($cues[$first], $cues[$first + 1], $options);
                }
                if ($lines !== null) {
                    break;
                }
            }
            if ($lines === null) {
                $index++;
                continue;
            }

            $anchors = $this->joinGroup([$cues[$first], $cues[$first + 1]], $anchors, false);
            $cues[$first]->setLinesByArray($lines);
            array_splice($cues, $first + 1, 1);
            $index = $first;
        }
        $this->setCommentsByAnchors($this->comments, $anchors);

        return $this;
    }


    private static function shortCueMergingIsShort(SubtitleCue $cue, MergeShortCuesOptions $options): bool
    {
        return round($cue->getEnd() - $cue->getStart(), 3) < round($options->minDuration, 3)
            || ($options->minCharacters !== null && self::shortCueMergingCharacters($cue->getLines()) < $options->minCharacters);
    }


    /**
     * Returns the lines of $first and $second joined into one cue, or null when the joined cue breaks a limit.
     *
     * @return ?list<string>
     */
    private static function shortCueMergingJoinLines(SubtitleCue $first, SubtitleCue $second, MergeShortCuesOptions $options): ?array
    {
        $speakers = self::shortCueMergingSpeakers($first);
        if (CueImage::isImageCue($first) || CueImage::isImageCue($second)
            || ($first->getAlignment() ?? 2) !== ($second->getAlignment() ?? 2)
            || $first->isForced() !== $second->isForced()
            || $speakers !== self::shortCueMergingSpeakers($second)
            || ($options->sameSpeakerOnly && $speakers === [])) {
            return null;
        }

        $firstText = rtrim(Markup::plainText($first->getText()));
        if ($options->keepSentenceEnds && preg_match('/[.?!]$/', $firstText) === 1) {
            return null;
        }

        $duration = round(max($first->getEnd(), $second->getEnd()) - $first->getStart(), 3);
        if (round($second->getStart() - $first->getEnd(), 3) > round($options->maxGap, 3)
            || (!$options->sameSpeakerOnly && $duration > round($options->maxDuration, 3))) {
            return null;
        }

        $lines = self::shortCueMergingWrap(self::shortCueMergingOneVoiceTag($first, $second, $speakers)
                                           ?? [...$first->getLines(), ...$second->getLines()],
                                           $options->maxCharactersPerLine, $options->maxLines);
        if ($lines === null || $options->maxCharactersPerSecond === null) {
            return $lines;
        }

        $characters = self::shortCueMergingCharacters($lines);
        if ($characters > 0 && ($duration > 0 ? $characters / $duration : INF) > $options->maxCharactersPerSecond) {
            return null;
        }

        return $lines;
    }


    /**
     * Joins the lines with a space, but starts a new line at each dialogue dash, and wraps them as wrapLines() does.
     *
     * @param list<string> $lines
     *
     * @return ?list<string> null when the text does not fit
     */
    private static function shortCueMergingWrap(array $lines, int $maxCharactersPerLine, int $maxLines): ?array
    {
        $segments = [];
        foreach ($lines as $line) {
            $words = self::fixesSplitIntoWords($line);
            if ($words === []) {
                continue;
            }

            $startsWithDash = preg_match('/^(?:\s|<[^>]*>)*[-\x{2010}\x{2013}\x{2014}]/u', $line) === 1;
            if ($segments === [] || $startsWithDash) {
                $segments[] = $words;
            } else {
                $segments[count($segments) - 1] = [...$segments[count($segments) - 1], ...$words];
            }
        }

        if (count($segments) <= 1) {
            $words      = $segments[0] ?? [];
            $lineStarts = self::fixesFindBreaks($words, $maxCharactersPerLine, $maxLines);
            $segments   = [];
            foreach ($lineStarts as $lineIndex => $start) {
                $segments[] = array_slice($words, $start, ($lineStarts[$lineIndex + 1] ?? count($words)) - $start);
            }
            $joined = self::fixesJoinLines($words, $lineStarts);
        } else {
            $joined = array_map(fn (array $words): string => implode(" ", array_column($words, "text")), $segments);
        }

        if (count($segments) > $maxLines) {
            return null;
        }
        foreach ($segments as $words) {
            if (self::fixesLineLength($words) > $maxCharactersPerLine) {
                return null;
            }
        }

        return $joined;
    }


    /**
     * Returns the lines of both cues under the <v> tag of $first when both cues start with a <v> tag of the one speaker, or null.
     *
     * @param list<string> $speakers
     *
     * @return ?list<string>
     */
    private static function shortCueMergingOneVoiceTag(SubtitleCue $first, SubtitleCue $second, array $speakers): ?array
    {
        $voiceTag = '/^<v(?:\.[^\s>]*)?\s+[^>]*>/i';
        if (count($speakers) !== 1 || preg_match($voiceTag, $first->getText(), $match) !== 1
            || preg_match($voiceTag, $second->getText()) !== 1) {
            return null;
        }

        $lines    = preg_replace('/<v(?:\.[^\s>]*)?\s+[^>]*>|<\/v>/i', "", [...$first->getLines(), ...$second->getLines()]);
        $lines[0] = $match[0] . $lines[0];
        if (preg_match('/<\/v>$/i', $second->getText()) === 1) {
            $lines[count($lines) - 1] .= "</v>";
        }

        return $lines;
    }


    /**
     * @return list<string> the sorted names of the <v> speakers in the cue
     */
    private static function shortCueMergingSpeakers(SubtitleCue $cue): array
    {
        preg_match_all('/<v(?:\.[^\s>]*)?\s+([^>]*)>/i', $cue->getText(), $matches);
        $speakers = array_unique(array_map("trim", $matches[1]));
        sort($speakers);

        return $speakers;
    }


    /**
     * @param array<string> $lines
     */
    private static function shortCueMergingCharacters(array $lines): int
    {
        return array_sum(array_map(fn (string $line): int => Markup::visibleLength($line), $lines));
    }
}
