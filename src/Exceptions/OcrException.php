<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * OcrException means that an OCR engine failed to read an image, for example because Tesseract exited with an error.
 */
final class OcrException extends GenericException
{
    protected const CODE = 107;
}
