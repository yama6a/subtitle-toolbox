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

    abstract public function format(Subtitle $subtitle, array $options = []): string;


    /**
     * Applies OPTION_LINE_ENDING and OPTION_BOM to the LF output of a formatter.
     */
    protected function applyOutputOptions(string $output, array $options): string
    {
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
}
