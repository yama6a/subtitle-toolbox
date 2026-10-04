<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class WordHighlightOptions
{
    // <v> names a speaker, so it cannot mark a word.
    private const STYLE_TAGS = ["b", "i", "u", "s", "font"];


    /**
     * Creates the options for WordHighlight::apply(), for example new WordHighlightOptions(style: "b").
     */
    public function __construct(
        public readonly string $style = "u",
        public readonly WordHighlightMode $mode = WordHighlightMode::Word,
        public readonly ?int $maxWordsPerCue = null,
    ) {
        if (!in_array($this->getTagName(), self::STYLE_TAGS, true)) {
            throw new InvalidArgumentException("The style $style must be one of the core markup tags b, i, u, s or " .
                                               "font, for example u or font color=\"#ffff00\".");
        }

        if ($maxWordsPerCue !== null && $maxWordsPerCue < 1) {
            throw new InvalidArgumentException("The maximum of $maxWordsPerCue words per cue must be 1 or more.");
        }
    }


    /**
     * Returns the tag name of the style, for example "font" for font color="#ffff00".
     */
    public function getTagName(): string
    {
        return strtolower(preg_split("/\s+/", trim($this->style))[0]);
    }
}
