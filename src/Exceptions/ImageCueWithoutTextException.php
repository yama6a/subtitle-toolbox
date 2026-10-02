<?php

namespace SubtitleToolbox\Exceptions;

class ImageCueWithoutTextException extends GenericException
{
    public function getErrorCode(): int
    {
        return 103;
    }
}
