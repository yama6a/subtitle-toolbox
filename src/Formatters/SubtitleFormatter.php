<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Options;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

abstract class SubtitleFormatter
{
    public const OPTION_STRIP_ALL_XML_TAGS = "OPTION_STRIP_ALL_XML_TAGS";
    public const OPTION_LINE_ENDING        = "lineEnding";
    public const OPTION_BOM                = "bom";
    public const OPTION_SKIP_IMAGE_CUES    = "skipImageCues";

    /** @var array<class-string, array<string, true>|null> formatter class => option keys */
    private static array $optionKeys = [];

    abstract public function format(Subtitle $subtitle, array $options = []): string;


    /**
     * Applies OPTION_LINE_ENDING and OPTION_BOM to the LF output of a formatter.
     */
    protected function applyOutputOptions(string $output, array $options): string
    {
        $this->rejectUnknownOptions($options);
        $lineEnding = $options[self::OPTION_LINE_ENDING] ?? StringHelpers::UNIX_LINE_ENDING;
        if ($lineEnding === StringHelpers::WINDOWS_LINE_ENDING) {
            $output = preg_replace('/\r?\n/', StringHelpers::WINDOWS_LINE_ENDING, $output);
        } elseif ($lineEnding !== StringHelpers::UNIX_LINE_ENDING) {
            throw new InvalidArgumentException("The option " . self::OPTION_LINE_ENDING . " must be \"\\n\" or \"\\r\\n\".");
        }

        $bom = Options::flag($options, self::OPTION_BOM);
        if (!is_bool($bom) && $bom !== null) {
            throw new InvalidArgumentException("The option " . self::OPTION_BOM . " must be true or false.");
        }

        return match ($bom) {
            true    => StringHelpers::addUtf8Bom($output),
            false   => StringHelpers::removeUtf8Bom($output),
            default => $output,
        };
    }


    /**
     * Throws on a string key that is not the value of an OPTION_* constant of the formatter, such as a misspelled key.
     *
     * @throws InvalidArgumentException
     */
    protected function rejectUnknownOptions(array $options): void
    {
        if (!array_key_exists(static::class, self::$optionKeys)) {
            self::$optionKeys[static::class] = $this->optionKeys();
        }
        $known = self::$optionKeys[static::class];
        // A formatter outside the library may read keys that it declares in other ways.
        if ($known === null) {
            return;
        }

        $unknown = array_filter(array_keys($options), fn (int|string $key): bool => is_string($key) && !isset($known[$key]));
        if ($unknown !== []) {
            $class = substr(strrchr(static::class, "\\"), 1);
            throw new InvalidArgumentException("$class does not know the option \"" . reset($unknown) . "\". It knows the options " .
                                               implode(", ", array_keys($known)) . ".");
        }
    }


    /**
     * @return array<string, true>|null the values of the OPTION_* constants, or null for a formatter outside the library
     */
    private function optionKeys(): ?array
    {
        $class = new \ReflectionClass($this);
        if (dirname($class->getFileName()) !== __DIR__) {
            return null;
        }

        return array_fill_keys(array_filter(
            $class->getConstants(),
            fn (mixed $value, string $name): bool => str_starts_with($name, "OPTION_") && is_string($value),
            ARRAY_FILTER_USE_BOTH
        ), true);
    }
}
