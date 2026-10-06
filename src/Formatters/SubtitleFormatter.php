<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\FormatWriteOptions;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

/**
 * The base class of the formatters of this library. Only the library extends it. Its protected members are not API and
 * can change in any release.
 */
abstract class SubtitleFormatter
{
    /** @var class-string<FormatWriteOptions>|null the class that WriteOptions::$format must have, or null for none */
    protected const FORMAT_OPTIONS = null;


    abstract public function format(Subtitle $subtitle, ?WriteOptions $options = null): string;


    /**
     * Applies the line ending and the BOM of $options to the LF output of a formatter.
     */
    protected function applyOutputOptions(string $output, WriteOptions $options): string
    {
        $this->formatOptions($options);
        if ($options->lineEnding === LineEnding::Crlf) {
            $output = preg_replace('/\r?\n/', LineEnding::Crlf->value, $output);
        }

        return match ($options->bom) {
            true    => StringHelpers::addUtf8Bom($output),
            false   => StringHelpers::removeUtf8Bom($output),
            default => $output,
        };
    }


    /**
     * Returns WriteOptions::$format, or null when $options holds none.
     *
     * @throws InvalidArgumentException when WriteOptions::$format holds the settings of another format.
     */
    protected function formatOptions(WriteOptions $options): ?FormatWriteOptions
    {
        $class = static::FORMAT_OPTIONS;
        if ($options->format !== null && ($class === null || !$options->format instanceof $class)) {
            $formatter = substr(strrchr(static::class, "\\"), 1);
            $given     = substr(strrchr($options->format::class, "\\"), 1);
            throw new InvalidArgumentException($class === null
                ? "$formatter takes no format options, got $given."
                : "$formatter takes " . substr(strrchr($class, "\\"), 1) . ", got $given.");
        }

        return $options->format;
    }
}
