<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\Parsers\AssFormatLines;

/**
 * Reads the Field=Value pairs of AssWriteOptions::$style and applies them to the Default style.
 *
 * @internal
 */
final class AssStyleOverride
{
    /**
     * Returns the values by the field names of the [V4+ Styles] Format line. A later pair for the same field wins.
     *
     * @return array<string, string>
     * @throws InvalidArgumentException for a pair without "=", a line break in a value or an unknown field.
     */
    public static function parse(string $style): array
    {
        $fields = array_values(array_diff(AssFormatLines::ASS_STYLE_FORMAT, ["Name"]));
        $values = [];
        foreach (explode(",", $style) as $pair) {
            if (!preg_match('/^\s*([^=\s]+)\s*=\s*([^\x00-\x1f]*?)\s*$/', $pair, $matches)) {
                throw new InvalidArgumentException("The ASS style \"$style\" must hold Field=Value pairs such as Fontsize=48, got \"$pair\".");
            }

            $field = self::findName($fields, $matches[1]);
            if ($field === null) {
                throw new InvalidArgumentException("The ASS style field \"$matches[1]\" does not exist. Use one of: " . implode(", ", $fields) . ".");
            }
            $values[$field] = $matches[2];
        }

        return $values;
    }


    /**
     * Sets the values in each style named Default, and adds a Default style when there is none.
     *
     * @param list<string>                $format the fields of the Format line of the styles
     * @param list<array<string, string>> $styles
     * @param array<string, string>       $defaultStyle the values of a new Default style by ASS field name
     * @return list<array<string, string>>
     * @throws UnwritableContentException when the Format line has no field for a value.
     */
    public static function apply(string $style, array $format, array $styles, array $defaultStyle): array
    {
        $values = [];
        foreach (self::parse($style) as $field => $value) {
            $formatField = self::findName($format, $field);
            if ($formatField === null) {
                throw new UnwritableContentException("The Format line of the styles has no field $field. It holds: " . implode(", ", $format) . ".");
            }
            $values[$formatField] = $value;
        }

        $found = false;
        foreach ($styles as $index => $existing) {
            $nameField = self::findName(array_keys($existing), "Name");
            if ($nameField !== null && strcasecmp(ltrim(trim($existing[$nameField]), "*"), "Default") === 0) {
                $styles[$index] = array_replace($existing, $values);
                $found          = true;
            }
        }
        if (!$found) {
            $new = [];
            foreach ($format as $field) {
                $new[$field] = $defaultStyle[self::findName(array_keys($defaultStyle), $field) ?? ""] ?? "0";
            }
            array_unshift($styles, array_replace($new, $values));
        }

        return $styles;
    }


    /**
     * @param list<string> $names
     */
    private static function findName(array $names, string $name): ?string
    {
        foreach ($names as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
