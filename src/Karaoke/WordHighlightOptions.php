<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;

final class WordHighlightOptions
{
    /**
     * @param string            $style          The markup tag that marks the active word, for example "b" or "font color=\"#ffff00\"".
     * @param WordHighlightMode $mode           Which words the style marks.
     * @param ?int              $maxWordsPerCue The number of words that one cue shows around the active word. Null shows all words.
     */
    public function __construct(
        public readonly string $style = "u",
        public readonly WordHighlightMode $mode = WordHighlightMode::Word,
        public readonly ?int $maxWordsPerCue = null,
    ) {
        if (!in_array($this->getTagName(), Markup::STYLE_TAGS, true)) {
            throw new InvalidArgumentException("The style $style must be one of the core markup tags b, i, u, s or " .
                                               "font, for example u or font color=\"#ffff00\".");
        }

        if ($maxWordsPerCue !== null && $maxWordsPerCue < 1) {
            throw new InvalidArgumentException("The maximum of $maxWordsPerCue words per cue must be 1 or more.");
        }
    }


    /**
     * Returns the tag name of the style, for example "font" for font color="#ffff00".
     *
     * @internal
     */
    public function getTagName(): string
    {
        return Markup::tagName($this->style);
    }
}
