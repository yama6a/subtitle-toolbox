<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use JsonException;
use SubtitleToolbox\Exceptions\UnwritableContentException;

/**
 * @internal
 */
final class JsonOutput
{
    /**
     * Encodes $data with $flags and JSON_THROW_ON_ERROR. Data that JSON cannot hold, such as text that is not valid
     * UTF-8 or a value of INF, throws UnwritableContentException.
     */
    public static function encode(mixed $data, int $flags): string
    {
        try {
            return json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $advice = $exception->getCode() === JSON_ERROR_UTF8 ? " Read a file in another encoding with its source encoding." : "";
            throw new UnwritableContentException("JSON cannot hold the subtitle: " . $exception->getMessage() . "." . $advice, $exception);
        }
    }
}
