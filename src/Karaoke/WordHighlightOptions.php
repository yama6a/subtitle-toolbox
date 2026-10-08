<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;

final class WordHighlightOptions
{
    /**
     * Creates the options for WordHighlight::apply(), for example new WordHighlightOptions(style: "b").
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
