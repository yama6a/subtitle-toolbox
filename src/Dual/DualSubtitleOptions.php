<?php

declare(strict_types=1);

namespace SubtitleToolbox\Dual;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\OptionChecks;

final class DualSubtitleOptions
{
    /**
     * Creates the options for DualSubtitle::fromPair(), for example new DualSubtitleOptions(secondaryStyle: "i").
     */
    public function __construct(
        public readonly DualSubtitleMode $mode = DualSubtitleMode::Stack,
        public readonly float $snapTolerance = 0.25,
        public readonly ?string $secondaryStyle = null,
        public readonly int $secondaryAlignment = 8,
    ) {
        OptionChecks::nonNegativeFinite($snapTolerance, "The snap tolerance %s must not be negative.");

        if ($secondaryStyle !== null && !in_array($this->getSecondaryTagName(), Markup::CORE_TAGS, true)) {
            throw new InvalidArgumentException("The secondary style $secondaryStyle must be a core markup tag " .
                                               "such as i or font color=\"#ffff00\".");
        }

        if (!OptionChecks::isAlignment($secondaryAlignment)) {
            throw new InvalidArgumentException("Cannot set alignment $secondaryAlignment - " .
                                               "the alignment must be a number from 1 to 9!");
        }
    }


    /**
     * Returns the tag name of the secondary style, for example "font" for font color="#ffff00", or null.
     *
     * @internal
     */
    public function getSecondaryTagName(): ?string
    {
        if ($this->secondaryStyle === null) {
            return null;
        }

        return strtolower(preg_split("/\s+/", trim($this->secondaryStyle))[0]);
    }
}
