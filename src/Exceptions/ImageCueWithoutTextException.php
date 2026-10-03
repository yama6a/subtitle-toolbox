<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

class ImageCueWithoutTextException extends GenericException
{
    public function getErrorCode(): int
    {
        return 103;
    }
}
