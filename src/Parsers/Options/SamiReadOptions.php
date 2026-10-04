<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SamiReadOptions implements FormatReadOptions
{
    /**
     * @param ?string $language The language class to read, such as "ENUSCC". Null reads the first class of the STYLE block.
     */
    public function __construct(public readonly ?string $language = null)
    {
        if ($language !== null && trim($language) === "") {
            throw new InvalidArgumentException("The language must not be empty.");
        }
    }
}
