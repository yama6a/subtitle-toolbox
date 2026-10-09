<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Markup;
use SubtitleToolbox\SubtitleCue;

/**
 * Collapses the rolling cues of YouTube automatic captions in WebVTT, as yt-dlp --write-auto-subs saves them.
 *
 * Each cue repeats the line of the cue before it above a new line with <c> word timestamps.
 * A 10 ms cue that repeats the last line follows each cue.
 * The collapse drops the 10 ms cues and the repeated lines.
 * The next cue starts where the dropped 10 ms cue started.
 *
 * @internal
 */
final class YouTubeRollingCues
{
    private const REPEAT_DURATION = 0.0105;

    private const C_WORD_TIMESTAMP = '/<' . Markup::WORD_TIMESTAMP . '><c[.>]/';


    /**
     * Yields the cues with their keys. The collapse starts only when a cue has <c> word timestamps.
     * It decides about a 10 ms cue at the cue after it, so it holds at most 2 cues.
     *
     * @param iterable<int, SubtitleCue> $cues in file order
     *
     * @return Generator<int, SubtitleCue>
     */
    public static function collapse(iterable $cues): Generator
    {
        $hasWordTimestamps = false;
        $held              = [];
        foreach ($cues as $key => $cue) {
            $hasWordTimestamps = $hasWordTimestamps || preg_match(self::C_WORD_TIMESTAMP, $cue->getText()) === 1;
            if (count($held) === 2 && $hasWordTimestamps) {
                self::removeRepeatedLine($cue, array_pop($held));
                yield from $held;
                $held = [];
            }
            if (count($held) === 1 && self::repeatsLastLine($cue, end($held))) {
                $held[$key] = $cue;
                continue;
            }

            yield from $held;
            $held = [$key => $cue];
        }

        if (count($held) === 2 && $hasWordTimestamps) {
            array_pop($held);
        }
        yield from $held;
    }


    private static function repeatsLastLine(SubtitleCue $cue, SubtitleCue $previous): bool
    {
        $previousLines = $previous->getLines();
        $duration      = $cue->getEnd() - $cue->getStart();

        return $duration >= 0 && $duration <= self::REPEAT_DURATION
               && $cue->getStart() >= $previous->getStart()
               && $previousLines !== []
               && self::plainLines($cue) === [self::plain(end($previousLines))];
    }


    private static function removeRepeatedLine(SubtitleCue $cue, SubtitleCue $repeat): void
    {
        $lines = $cue->getLines();
        if (count($lines) < 2 || self::plain($lines[0]) !== self::plainLines($repeat)[0]) {
            return;
        }

        $cue->setLines(array_slice($lines, 1));
        $cue->setStart(min($cue->getStart(), $repeat->getStart()));
    }


    /**
     * @return list<string>
     */
    private static function plainLines(SubtitleCue $cue): array
    {
        return array_map(self::plain(...), $cue->getLines());
    }


    private static function plain(string $line): string
    {
        return preg_replace('/\s+/', ' ', Markup::visibleText($line)) ?? $line;
    }
}
