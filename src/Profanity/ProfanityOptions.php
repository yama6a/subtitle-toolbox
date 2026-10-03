<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

use Closure;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class ProfanityOptions
{
    public const MASK_STARS        = "stars";
    public const MASK_FIRST_LETTER = "firstLetter";
    public const MASK_REMOVE       = "remove";
    public const MASK_NONE         = "none";

    /** @var list<string> */
    public readonly array $words;


    /**
     * Creates the filter settings, see the README section "Profanity filter".
     *
     * @param list<string> $words
     * @param string|Closure(string): string $mask
     */
    public function __construct(
        array $words = [],
        public readonly string|Closure $mask = self::MASK_STARS,
        public readonly float $padding = 0.0,
        ?string $wordFile = null,
    ) {
        if ($wordFile !== null) {
            $words = [...$words, ...self::readWordFile($wordFile)];
        }

        foreach ($words as $word) {
            if (!is_string($word) || preg_match('/^[^*\s](?:[^*]*[^*\s])?\*?$/u', $word) !== 1) {
                throw new InvalidArgumentException("A profanity word must be a non-empty UTF-8 string with a * only at the end, got " .
                                                   var_export($word, true) . ".");
            }
        }
        if ($words === []) {
            throw new InvalidArgumentException("The profanity word list is empty.");
        }
        if (is_string($mask) && !in_array($mask, [self::MASK_STARS, self::MASK_FIRST_LETTER, self::MASK_REMOVE, self::MASK_NONE], true)) {
            throw new InvalidArgumentException("Unknown profanity mask \"$mask\".");
        }
        if ($padding < 0) {
            throw new InvalidArgumentException("The padding must not be negative, got $padding.");
        }

        $this->words = array_values(array_unique($words));
    }


    /**
     * @return list<string>
     */
    private static function readWordFile(string $path): array
    {
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            throw new InvalidArgumentException("Cannot read the word file $path.");
        }

        $lines = preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', "", $content));

        return array_values(array_filter(array_map("trim", $lines), fn (string $line): bool => $line !== ""));
    }
}
