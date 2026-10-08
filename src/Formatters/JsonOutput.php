<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use JsonException;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\LineEnding;

/**
 * @internal
 */
final class JsonOutput
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;


    /**
     * Encodes $data as a JSON document. A pretty printed document ends with a line feed, a compact one does not.
     */
    public static function document(mixed $data, bool $prettyPrint, int $flags = self::FLAGS): string
    {
        return $prettyPrint ? self::encode($data, $flags | JSON_PRETTY_PRINT) . LineEnding::Lf->value : self::encode($data, $flags);
    }


    /**
     * Encodes $data with $flags and JSON_THROW_ON_ERROR.
     * Data that JSON cannot hold, such as invalid UTF-8 or INF, throws UnwritableContentException.
     */
    private static function encode(mixed $data, int $flags): string
    {
        try {
            return json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $advice = $exception->getCode() === JSON_ERROR_UTF8 ? " Read a file in another encoding with its source encoding." : "";
            throw new UnwritableContentException("JSON cannot hold the subtitle: " . $exception->getMessage() . "." . $advice, $exception);
        }
    }
}
