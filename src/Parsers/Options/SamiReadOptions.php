<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class SamiReadOptions implements FormatReadOptions
{
    /**
     * @param ?string $languageClass The SAMI class to read, such as "ENUSCC". Null reads the first class of the STYLE block.
     */
    public function __construct(public readonly ?string $languageClass = null)
    {
        if ($languageClass !== null && trim($languageClass) === "") {
            throw new InvalidArgumentException("The language class must not be empty.");
        }
    }
}
