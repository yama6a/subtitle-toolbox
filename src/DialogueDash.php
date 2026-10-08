<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The dashes that start a dialogue line.
 *
 * @internal
 */
final class DialogueDash
{
    /** The hyphen, U+2010, the en dash and the em dash, as the body of a regex character class. */
    public const CHARACTERS = '\-\x{2010}\x{2013}\x{2014}';

    // A dash before a digit, such as "-20 degrees", is a minus sign and starts no dialogue.
    public const REGEX = '/^[' . self::CHARACTERS . '](?![' . self::CHARACTERS . '])[ \t\x{00A0}]*(?=[^\s\p{N}])/u';
}
