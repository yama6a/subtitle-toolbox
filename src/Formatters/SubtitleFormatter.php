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

    /** WriteOptions::$bom when it is null. Null leaves the output as the formatter wrote it. */
    protected const DEFAULT_BOM = null;


    abstract public function format(Subtitle $subtitle, ?WriteOptions $options = null): string;


    /**
     * Applies the line ending and the BOM of $options to the LF output of a formatter.
     */
    protected function applyOutputOptions(string $output, WriteOptions $options): string
    {
        $this->rejectForeignOptions($options);

        return $this->applyBom($this->applyLineEnding($output, $options), $options);
    }


    /**
     * Converts the LF output of a formatter to the line ending of $options.
     */
    protected function applyLineEnding(string $output, WriteOptions $options): string
    {
        return $options->lineEnding === LineEnding::Crlf ? preg_replace('/\r?\n/', LineEnding::Crlf->value, $output) : $output;
    }


    /**
     * Adds or removes the BOM as WriteOptions::$bom says, or as DEFAULT_BOM says when it is null.
     */
    protected function applyBom(string $output, WriteOptions $options): string
    {
        return match ($options->bom ?? static::DEFAULT_BOM) {
            true    => StringHelpers::addUtf8Bom($output),
            false   => StringHelpers::removeUtf8Bom($output),
            default => $output,
        };
    }


    /**
     * Returns WriteOptions::$format, or the default options of the format when $options holds none. Returns null for a
     * formatter without format options.
     *
     * @throws InvalidArgumentException when WriteOptions::$format holds the settings of another format.
     */
    protected function formatOptions(WriteOptions $options): ?FormatWriteOptions
    {
        $this->rejectForeignOptions($options);
        $class = static::FORMAT_OPTIONS;

        return $options->format ?? ($class === null ? null : new $class());
    }


    /**
     * @throws InvalidArgumentException when WriteOptions::$format holds the settings of another format.
     */
    protected function rejectForeignOptions(WriteOptions $options): void
    {
        $class = static::FORMAT_OPTIONS;
        if ($options->format !== null && ($class === null || !$options->format instanceof $class)) {
            $formatter = $this->shortName(static::class);
            $given     = $this->shortName($options->format::class);
            throw new InvalidArgumentException($class === null
                ? "$formatter takes no format options, got $given."
                : "$formatter takes " . $this->shortName($class) . ", got $given.");
        }
    }


    private function shortName(string $class): string
    {
        return substr(strrchr("\\" . $class, "\\"), 1);
    }
}
