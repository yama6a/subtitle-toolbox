<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMElement;

/**
 * Resolves the TTML styles that become core markup. TtmlParser reads them, and TtmlFormatter writes the values
 * that undo an inherited style.
 *
 * @internal
 */
final class TtmlStyles
{
    public const DEFAULT_STYLE = ["b" => false, "i" => false, "u" => false, "s" => false, "color" => null];

    // Styles can refer to each other in a loop, so the resolver follows at most 20 references.
    private const MAX_STYLE_DEPTH = 20;

    /** @var array<string, DOMElement> */
    public readonly array $styles;

    /** @var array<string, DOMElement> */
    public readonly array $regions;


    public function __construct(private readonly ?string $namespace, ?DOMElement $head)
    {
        $this->styles  = $head === null ? [] : $this->elementsById($head, "style");
        $this->regions = $head === null ? [] : $this->elementsById($head, "region");
    }


    /**
     * Returns the properties of the referenced styles and of the own tts: attributes. The own attributes win.
     *
     * @return array<string, string>
     */
    public function ownProperties(DOMElement $element, int $depth = 0): array
    {
        $properties = [];
        if ($depth < self::MAX_STYLE_DEPTH) {
            foreach ($this->styleIds($element) as $id) {
                if (isset($this->styles[$id])) {
                    $properties = [...$properties, ...$this->ownProperties($this->styles[$id], $depth + 1)];
                }
            }
        }

        return [...$properties, ...self::stylingAttributes($element)];
    }


    /**
     * Collects the style references, the nested <style> elements and the own attributes of a region. The own
     * attributes win.
     *
     * @return array<string, string>
     *
     * @see https://www.w3.org/TR/ttml2/#semantics-style-inheritance-content
     */
    public function regionProperties(?string $regionId): array
    {
        if ($regionId === null || !isset($this->regions[$regionId])) {
            return [];
        }

        $region     = $this->regions[$regionId];
        $properties = $this->ownProperties($region);
        foreach ($region->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === "style" && $child->namespaceURI === $this->namespace) {
                $properties = [...$properties, ...$this->ownProperties($child)];
            }
        }

        return [...$properties, ...self::stylingAttributes($region)];
    }


    /**
     * @return list<string>
     */
    public function styleIds(DOMElement $element): array
    {
        return preg_split("/\s+/", trim($element->getAttribute("style")), -1, PREG_SPLIT_NO_EMPTY);
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#semantics-style-resolution-processing-sss
     */
    public static function apply(array $style, array $properties): array
    {
        foreach ($properties as $name => $value) {
            $value = trim($value);
            switch ($name) {
                case "fontWeight":
                    $style["b"] = $value === "bold";
                    break;
                case "fontStyle":
                    $style["i"] = $value === "italic" || $value === "oblique";
                    break;
                case "textDecoration":
                    $decorations = preg_split("/\s+/", $value);
                    if (in_array("none", $decorations, true)) {
                        $style["u"] = $style["s"] = false;
                    }
                    $style["u"] = in_array("underline", $decorations, true)
                                  || $style["u"] && !in_array("noUnderline", $decorations, true);
                    $style["s"] = in_array("lineThrough", $decorations, true)
                                  || $style["s"] && !in_array("noLineThrough", $decorations, true);
                    break;
                case "color":
                    $color          = self::normalizeColor($value);
                    // White is the default text color of every player, so it adds no markup.
                    $style["color"] = $color === "#ffffff" ? null : $color;
                    break;
            }
        }

        return $style;
    }


    /**
     * @return array<string, string>
     */
    public static function stylingAttributes(DOMElement $element): array
    {
        $properties = [];
        foreach ($element->attributes as $attribute) {
            if (in_array($attribute->namespaceURI, TtmlNamespaces::STYLING, true)) {
                $properties[$attribute->localName] = $attribute->value;
            } elseif ($attribute->namespaceURI === null && str_starts_with($attribute->nodeName, "tts:")) {
                $properties[substr($attribute->nodeName, 4)] = $attribute->value;
            }
        }

        return $properties;
    }


    /**
     * Drops the alpha channel, because core markup has none.
     *
     * @see https://www.w3.org/TR/ttml2/#style-value-color
     */
    private static function normalizeColor(string $color): ?string
    {
        $color = strtolower(trim($color));
        if (preg_match("/^#([0-9a-f]{6})([0-9a-f]{2})?$/", $color, $matches)) {
            return "#" . $matches[1];
        }
        if (preg_match("/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*\d{1,3}\s*)?\)$/", $color, $matches)) {
            return sprintf("#%02x%02x%02x", min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3]));
        }

        return ColorNames::TTML[$color] ?? null;
    }


    /**
     * @return array<string, DOMElement>
     */
    private function elementsById(DOMElement $head, string $localName): array
    {
        $elements = [];
        foreach ($head->getElementsByTagName("*") as $element) {
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            if ($element->localName === $localName && $element->namespaceURI === $this->namespace && $id !== ""
                && !isset($elements[$id])) {
                $elements[$id] = $element;
            }
        }

        return $elements;
    }
}
