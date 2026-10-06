<?php

declare(strict_types=1);

namespace SubtitleToolbox\Exceptions;

/**
 * TranslationException means that a translation service failed, for example with HTTP 403 for a wrong API key.
 */
final class TranslationException extends GenericException
{
    protected const CODE = 109;
}
