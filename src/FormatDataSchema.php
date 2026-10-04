<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\ParsingException;

/**
 * FormatDataSchema checks the types of the format data fields that the formatters read, so that fromArray() and
 * JsonParser reject a bad field with its path before a formatter fails on it. Other fields pass as they are.
 *
 * The format data of a format is an object of fields, or "strings" for an object of strings with any keys.
 * A type is "string", "int", "bool", "number", "scalar", "ttiBlock" or "timeBase", with "?" in front for null too,
 * or an array: ["list", type] for an array of values of one type, ["names", type] for an object whose keys are
 * names, ["object", [key => type]] for named fields, where "!" in front of a key marks a required field,
 * ["range", min, max] for an integer in a range, and ["keys", list of names, type] for an object with only these keys.
 *
 * @internal
 */
final class FormatDataSchema
{
    private const STRINGS = ["list", "string"];

    private const ATTRIBUTES = ["names", "string"];

    private const TTI_BLOCKS = ["list", "ttiBlock"];

    private const FILE = [
        "ass"        => [
            "sectionOrder"       => self::STRINGS,
            "scriptInfoComments" => self::STRINGS,
            "scriptInfo"         => ["list", "string"],
            "stylesSection"      => "string",
            "styleFormat"        => self::STRINGS,
            "styles"             => ["list", ["list", "string"]],
            "eventFormat"        => self::STRINGS,
            "commentEvents"      => ["list", self::ATTRIBUTES],
            "sections"           => ["list", self::STRINGS],
        ],
        "csv"        => [
            "delimiter"  => "string",
            "!header"    => ["?list", "string"],
            "!roles"     => ["keys", ["identifier", "start", "end", "duration", "speaker", "text"], ["range", 0, 999]],
            "!width"     => ["range", 0, 1000],
            "timeFormat" => "string",
            "frameRate"  => "?number",
        ],
        "ffmeta"     => [
            "tags"    => ["list", "?string"],
            "streams" => ["list", ["list", "string"]],
        ],
        "itt"        => [
            "frameRate"           => "string",
            "frameRateMultiplier" => "string",
        ],
        "lrc"        => [
            "idTags" => ["list", "string"],
        ],
        "microdvd"   => [
            "frameRate" => "number",
        ],
        "mpsub"      => "strings",
        "sami"       => [
            "style"     => "?string",
            "class"     => "?string",
            "samiParam" => "?string",
        ],
        "scc"        => [
            "dropFrame" => "bool",
        ],
        "stl"        => [
            "gsi"                        => ["list", "string"],
            "startOfProgrammeSubtracted" => "bool",
            "firstSubtitleNumber"        => "?int",
            "comments"                   => ["list", ["object", ["!text" => "string", "!blocks" => self::TTI_BLOCKS]]],
        ],
        "subviewer"  => [
            "header" => ["list", "string"],
            "style"  => "?string",
        ],
        "ttml"       => [
            "namespace"  => "string",
            "namespaces" => self::ATTRIBUTES,
            "head"       => "string",
            "attributes" => self::ATTRIBUTES,
            "body"       => self::ATTRIBUTES,
        ],
        "vtt"        => [
            "header"      => "string",
            "headerLines" => self::STRINGS,
            "regions"     => ["list", ["list", "string"]],
            "styles"      => self::STRINGS,
        ],
    ];

    private const CUE = [
        "ass"        => [
            "fields" => self::ATTRIBUTES,
            "text"   => "string",
        ],
        "csv"        => [
            "columns" => ["list", "scalar"],
        ],
        "ffmeta"     => [
            "timeBase" => "timeBase",
            "tags"     => ["list", "string"],
        ],
        "image"      => [
            "png"          => "string",
            "x"            => "int",
            "y"            => "int",
            "width"        => "int",
            "height"       => "int",
            "screenWidth"  => "int",
            "screenHeight" => "int",
            "forced"       => "bool",
        ],
        "lrc"        => [
            "endLine" => "bool",
        ],
        "microdvd"   => [
            "lines" => ["list", ["object", ["!codes" => "string", "!color" => "?string", "!tags" => self::STRINGS, "otherCodes" => "string"]]],
        ],
        "sami"       => [
            "paragraphs" => ["list", ["object", ["!attributes" => self::ATTRIBUTES, "!html" => "string"]]],
        ],
        "srt"        => [
            "coordinates" => ["object", ["!x1" => "int", "!x2" => "int", "!y1" => "int", "!y2" => "int"]],
        ],
        "stl"        => [
            "subtitleGroupNumber" => "int",
            "cumulativeStatus"    => "int",
            "verticalPosition"    => "int",
            "justificationCode"   => "int",
            "blocks"              => self::TTI_BLOCKS,
        ],
        "ttml"       => [
            "attributes" => self::ATTRIBUTES,
            "div"        => self::ATTRIBUTES,
        ],
        "vtt"        => [
            "vertical" => "string",
            "line"     => "string",
            "position" => "string",
            "size"     => "string",
            "align"    => "string",
            "region"   => "string",
        ],
    ];


    /**
     * Throws ParsingException with the path of the first field of $data, the format data of format data key $key, that has the wrong type.
     *
     * @param string $path the path of $data, for example "cues[3].formatData.ass"
     */
    public static function check(string $key, array $data, string $path, bool $isCue): void
    {
        $fields = ($isCue ? self::CUE : self::FILE)[$key] ?? null;
        if ($fields === "strings") {
            self::checkType(["list", "string"], $data, $path);
        } elseif ($fields !== null && $data !== []) {
            self::checkType(["object", $fields], $data, $path);
        }
    }


    private static function checkType(string|array $type, mixed $value, string $path): void
    {
        if (is_string($type) && str_starts_with($type, "?")) {
            if ($value !== null) {
                self::checkType(substr($type, 1), $value, $path);
            }

            return;
        }
        if (is_array($type) && str_starts_with($type[0], "?")) {
            if ($value !== null) {
                self::checkType([substr($type[0], 1), ...array_slice($type, 1)], $value, $path);
            }

            return;
        }

        $valid = match (is_array($type) ? $type[0] : $type) {
            "string"   => is_string($value),
            "int"      => is_int($value),
            "bool"     => is_bool($value),
            "number"   => is_int($value) || (is_float($value) && is_finite($value)),
            "scalar"   => is_scalar($value) || $value === null,
            "ttiBlock" => is_string($value) && preg_match('/^[0-9A-Fa-f]{256}$/', $value) === 1,
            "timeBase" => is_string($value) && preg_match('/^[1-9]\d*\/[1-9]\d*$/', $value) === 1,
            "range"    => is_int($value) && $value >= $type[1] && $value <= $type[2],
            default    => is_array($value),
        };
        if (!$valid) {
            throw new ParsingException("The field $path must be " . self::describe($type) . ".");
        }
        if (!is_array($type) || $type[0] === "range") {
            return;
        }

        match ($type[0]) {
            "list"   => self::checkItems($type[1], $value, $path),
            "names"  => self::checkNames($type[1], $value, $path),
            "keys"   => self::checkKeys($type[1], $type[2], $value, $path),
            "object" => self::checkFields($type[1], $value, $path),
        };
    }


    private static function checkItems(string|array $type, array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            self::checkType($type, $item, self::child($path, $key));
        }
    }


    private static function checkNames(string|array $type, array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ParsingException("The field $path must be an object whose keys are names, not numbers.");
            }
            self::checkType($type, $item, "$path.$key");
        }
    }


    /**
     * @param list<string> $names
     */
    private static function checkKeys(array $names, string|array $type, array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            if (!in_array($key, $names, true)) {
                throw new ParsingException("The field $path must hold only the keys " . implode(", ", $names) . ".");
            }
            self::checkType($type, $item, "$path.$key");
        }
    }


    /**
     * @param array<string, string|array> $fields
     */
    private static function checkFields(array $fields, array $value, string $path): void
    {
        foreach ($fields as $key => $type) {
            $name = ltrim($key, "!");
            if (!array_key_exists($name, $value)) {
                if ($key !== $name) {
                    throw new ParsingException("The field $path.$name is missing.");
                }
                continue;
            }
            self::checkType($type, $value[$name], "$path.$name");
        }
    }


    private static function child(string $path, int|string $key): string
    {
        return is_int($key) ? "{$path}[$key]" : "$path.$key";
    }


    private static function describe(string|array $type): string
    {
        return match (is_array($type) ? $type[0] : $type) {
            "string"   => "a string",
            "int"      => "an integer",
            "bool"     => "a boolean",
            "number"   => "a finite number",
            "scalar"   => "a string, a number, a boolean or null",
            "ttiBlock" => "a TTI block of 256 hexadecimal digits",
            "timeBase" => "a time base such as \"1/1000\"",
            "range"    => "an integer from $type[1] to $type[2]",
            "list"     => "a list or an object",
            default    => "an object",
        };
    }
}
