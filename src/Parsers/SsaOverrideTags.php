<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * Reads the alignment override tags of SSA and ASS, which AssParser, SubRipParser and AssFormatter share.
 *
 * @internal
 */
final class SsaOverrideTags
{
    // Legacy SSA codes: 1 to 3 are bottom, +4 is top, +8 is middle. The values are numpad alignments.
    public const LEGACY_ALIGNMENTS = [1 => 1, 2 => 2, 3 => 3, 5 => 7, 6 => 8, 7 => 9, 9 => 4, 10 => 5, 11 => 6];


    /**
     * Returns the numpad alignment of an \an or \a tag, for example 8 for "\an8" and for "\a6". Returns null for
     * another tag.
     */
    public static function alignment(string $tag): ?int
    {
        if (preg_match('/^\\\\an([1-9])$/', $tag, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/^\\\\a(\d{1,2})$/', $tag, $matches)) {
            return self::LEGACY_ALIGNMENTS[(int) $matches[1]] ?? null;
        }

        return null;
    }
}
