<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * Reads the bold, italic, underline, strikeout and alignment fields of the ASS and SSA styles.
 *
 * @internal
 */
final class AssStyles
{
    private const FLAG_FIELDS = ["b" => "bold", "i" => "italic", "u" => "underline", "s" => "strikeout"];


    /**
     * Returns the style with the name, or null. A leading "*" is ignored and the last definition wins, as in libass.
     *
     * @param list<array<string, string>> $styles
     * @return ?array<string, string>
     */
    public static function find(array $styles, string $name): ?array
    {
        $name = ltrim(trim($name), "*");
        foreach (array_reverse($styles) as $style) {
            $styleName = ltrim(self::field($style, "name") ?? "", "*");
            if ($styleName === $name || (strcasecmp($name, "Default") === 0 && strcasecmp($styleName, "Default") === 0)) {
                return $style;
            }
        }

        return null;
    }


    /**
     * Returns the style of an event. An unknown name falls back to the "Default" style.
     *
     * @param list<array<string, string>> $styles
     * @return ?array<string, string>
     */
    public static function forEvent(array $styles, string $name): ?array
    {
        return self::find($styles, $name) ?? self::find($styles, "Default");
    }


    /**
     * Returns the core markup tag names that the style turns on, in the order b, i, u, s.
     *
     * @param ?array<string, string> $style
     * @return list<string>
     */
    public static function tags(?array $style): array
    {
        $tags = [];
        foreach (self::FLAG_FIELDS as $tag => $field) {
            if (in_array(trim(self::field($style ?? [], $field) ?? ""), ["-1", "1"], true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }


    /**
     * Returns the numpad alignment of the style, or null for bottom center and for a missing or invalid value.
     * SSA styles in a [V4 Styles] section use the legacy codes.
     *
     * @param ?array<string, string> $style
     */
    public static function alignment(?array $style, bool $legacyCodes): ?int
    {
        $value = trim(self::field($style ?? [], "alignment") ?? "");
        if (!preg_match('/^\d{1,2}$/', $value)) {
            return null;
        }

        $alignment = $legacyCodes ? SsaOverrideTags::SSA_ALIGNMENTS[(int) $value] ?? null : (int) $value;

        return $alignment === null || $alignment < 1 || $alignment > 9 || $alignment === 2 ? null : $alignment;
    }


    /**
     * @param array<string, string> $fields
     */
    private static function field(array $fields, string $name): ?string
    {
        foreach ($fields as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
