<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class TtmlParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ttml->value;

    private const TIMING_ATTRIBUTES = ["begin", "end", "dur"];

    // TTML 1, section 8.3.13, named colors.
    private const NAMED_COLORS = [
        "black"  => "#000000", "silver" => "#c0c0c0", "gray"    => "#808080", "white"  => "#ffffff",
        "maroon" => "#800000", "red"    => "#ff0000", "purple"  => "#800080", "fuchsia" => "#ff00ff",
        "magenta" => "#ff00ff", "green" => "#008000", "lime"    => "#00ff00", "olive"  => "#808000",
        "yellow" => "#ffff00", "navy"   => "#000080", "blue"    => "#0000ff", "teal"   => "#008080",
        "aqua"   => "#00ffff", "cyan"   => "#00ffff",
    ];

    private ?string $namespace;

    private DOMElement $root;

    /** @var array<string, DOMElement> */
    private array $styles = [];

    /** @var array<string, DOMElement> */
    private array $regions = [];

    /** @var array<string, string> */
    private array $agents = [];

    private float $frameRate;

    /** Frame labels per second of an SMPTE time code, which is ttp:frameRate without the multiplier. */
    private float $smpteFrameRate;

    private bool $smpteTimeBase;

    private string $dropMode;

    private float $subFrameRate;

    private float $tickRate;

    private int $paragraphIndex = 0;


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings       = [];
        $this->paragraphIndex = 0;
        $document             = $this->loadDocument(StringHelpers::removeUtf8Bom($rawSubtitle));
        $this->root      = $document->documentElement;
        $this->namespace = $this->root->namespaceURI;
        if ($this->root->localName !== "tt"
            || !in_array($this->namespace, [TtmlNamespaces::TTML, TtmlNamespaces::DFXP, null], true)) {
            throw new ParsingException("The root element is not a TTML <tt> element!");
        }

        $this->readTimingParameters();
        $head = $this->firstChild($this->root, "head");
        $body = $this->firstChild($this->root, "body");
        $this->styles  = $head === null ? [] : $this->elementsById($head, "style");
        $this->regions = $head === null ? [] : $this->elementsById($head, "region");
        $this->agents  = $head === null ? [] : $this->readAgents($head);

        $subtitle = new Subtitle();
        $language = $this->root->getAttributeNS(TtmlNamespaces::XML, "lang");
        if ($language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }

        $fileData = [
            "namespace"  => $this->namespace ?? "",
            "namespaces" => $this->readNamespaces($document),
            "attributes" => $this->readAttributes($this->root, ["xml:lang"]),
        ];
        if ($head !== null) {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, $this->removeTitle($head));
            $fileData["head"] = $document->saveXML($head);
        }
        if ($body !== null && $this->readAttributes($body, self::TIMING_ATTRIBUTES) !== []) {
            $fileData["body"] = $this->readAttributes($body, self::TIMING_ATTRIBUTES);
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        $cues = [];
        if ($body !== null) {
            $this->readContainer($cues, $body, 0.0, null, null, null, false, [], null);
        }

        return $subtitle->addCues($cues);
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#timing-value-time-expression
     */
    private function parseTimeExpression(string $expression): float
    {
        $expression = trim($expression);
        // Some tools write a comma as decimal separator, for example 00:00:01,500.
        if (preg_match("/^(\d{2,}):(\d{2}):(\d{2})(?:[.,](\d+)|:(\d{2,})(?:\.(\d+))?)?$/", $expression, $matches)) {
            $seconds = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            if (($matches[4] ?? "") !== "") {
                return $seconds + (float) ("0." . $matches[4]);
            }
            $frames = ($matches[5] ?? "") === "" ? 0 : (int) $matches[5];
            $frames += ($matches[6] ?? "") === "" ? 0 : (int) $matches[6] / $this->subFrameRate;
            if ($this->smpteTimeBase) {
                return ($seconds * $this->smpteFrameRate + $frames - $this->droppedFrames((int) $matches[1], (int) $matches[2]))
                       / $this->frameRate;
            }

            return $seconds + $frames / $this->frameRate;
        }

        if (preg_match("/^(\d+(?:\.\d+)?)(h|ms|m|s|f|t)$/", $expression, $matches)) {
            $count = (float) $matches[1];

            return match ($matches[2]) {
                "h"  => $count * 3600,
                "m"  => $count * 60,
                "s"  => $count,
                "ms" => $count / 1000,
                "f"  => $count / $this->frameRate,
                "t"  => $count / $this->tickRate,
            };
        }

        throw new ParsingException("The time expression \"$expression\" could not be parsed!");
    }


    /**
     * Counts the frame labels that drop-frame time code skips before hh:mm:00, as in SMPTE ST 12-1.
     *
     * @see https://www.w3.org/TR/ttml1/#time-expression-semantics-smpte
     */
    private function droppedFrames(int $hours, int $minutes): int
    {
        return match ($this->dropMode) {
            "dropNTSC" => ($hours * 54 + $minutes - intdiv($minutes, 10)) * 2,
            "dropPAL"  => ($hours * 27 + intdiv($minutes, 2) - intdiv($minutes, 20)) * 4,
            default    => 0,
        };
    }


    private function loadDocument(string $xml): DOMDocument
    {
        if (trim($xml) === "") {
            throw new ParsingException("The file is empty!");
        }

        // LIBXML_NONET blocks network access. Without LIBXML_NOENT and LIBXML_DTDLOAD, libxml loads no external entity.
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            throw new ParsingException("The file is not well-formed XML!");
        }

        return $document;
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#parameter-attribute-frameRate
     */
    private function readTimingParameters(): void
    {
        $frameRate  = $this->attribute($this->root, "ttp", "frameRate");
        $multiplier = preg_split("/[\s:]+/", trim($this->attribute($this->root, "ttp", "frameRateMultiplier") ?? "1 1"));
        if (count($multiplier) !== 2 || (float) $multiplier[0] <= 0 || (float) $multiplier[1] <= 0) {
            $multiplier = [1, 1];
        }

        $this->smpteFrameRate = (float) ($frameRate ?? 30) ?: 30;
        $this->frameRate      = $this->smpteFrameRate * (float) $multiplier[0] / (float) $multiplier[1];
        $this->smpteTimeBase  = trim($this->attribute($this->root, "ttp", "timeBase") ?? "") === "smpte";
        $this->dropMode       = trim($this->attribute($this->root, "ttp", "dropMode") ?? "nonDrop");
        $this->subFrameRate   = (float) ($this->attribute($this->root, "ttp", "subFrameRate") ?? 1) ?: 1;
        $tickRate             = $this->attribute($this->root, "ttp", "tickRate");
        $this->tickRate       = $tickRate !== null && (float) $tickRate > 0
            ? (float) $tickRate
            : ($frameRate !== null ? $this->frameRate : 1);
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#timing-time-intervals
     */
    /**
     * @param list<SubtitleCue> $cues
     */
    private function readContainer(
        array &$cues,
        DOMElement $container,
        float $parentBegin,
        ?float $parentEnd,
        ?string $region,
        ?string $textAlign,
        bool $preserveSpace,
        array $divAttributes,
        ?bool $forced
    ): void {
        [$begin, $end]  = $this->interval($container, $parentBegin, $parentEnd);
        $forced         = $this->forcedDisplay($container) ?? $forced;
        $region         = $container->hasAttribute("region") ? $container->getAttribute("region") : $region;
        $textAlign      = $this->ownStyleProperties($container)["textAlign"] ?? $textAlign;
        $preserveSpace  = $this->preservesSpace($container, $preserveSpace);
        if ($this->isTtElement($container, "div")) {
            $divAttributes = [
                ...$divAttributes,
                ...$this->readAttributes($container, [...self::TIMING_ATTRIBUTES, "xml:id", "region"]),
            ];
        }

        foreach ($container->childNodes as $child) {
            if ($this->isTtElement($child, "div")) {
                $this->readContainer($cues, $child, $begin, $end, $region, $textAlign, $preserveSpace, $divAttributes, $forced);
            } elseif ($this->isTtElement($child, "p")) {
                try {
                    $cue = $this->readParagraph($child, $begin, $end, $region, $textAlign, $preserveSpace, $forced);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $child->getLineNo(), $this->paragraphIndex++, $this->xmlLines($child));
                    continue;
                }
                $this->paragraphIndex++;
                if ($divAttributes !== []) {
                    $cue->setFormatData(self::FORMAT_DATA_KEY, [...$cue->findFormatData(self::FORMAT_DATA_KEY), "div" => $divAttributes]);
                }
                $cues[] = $cue;
            }
        }
    }


    /**
     * @return list<string>
     */
    private function xmlLines(DOMElement $element): array
    {
        $lines = array_map("trim", explode("\n", $element->ownerDocument->saveXML($element)));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ""));
    }


    private function readParagraph(
        DOMElement $paragraph,
        float $parentBegin,
        ?float $parentEnd,
        ?string $region,
        ?string $textAlign,
        bool $preserveSpace,
        ?bool $forced
    ): SubtitleCue {
        [$begin, $end] = $this->interval($paragraph, $parentBegin, $parentEnd);
        if ($end === null) {
            throw new ParsingException("The paragraph that begins at {$begin}s has no end time!");
        }

        $style = $this->resolveStyle($paragraph, ["b" => false, "i" => false, "u" => false, "s" => false, "color" => null]);
        $agent = $this->agentName($paragraph);
        $runs  = [];
        $this->collectRuns($paragraph, $style, $agent, $this->preservesSpace($paragraph, $preserveSpace), $runs);

        $cue = new SubtitleCue($begin, $end, $this->runsToLines($runs));
        $id  = $paragraph->getAttributeNS(TtmlNamespaces::XML, "id");
        $cue->setIdentifier($id === "" ? null : $id);

        $attributes = $this->readAttributes($paragraph, [...self::TIMING_ATTRIBUTES, "xml:id"]);
        if (!$paragraph->hasAttribute("region") && $region !== null) {
            $attributes["region"] = $region;
        }
        $cue->setFormatData(self::FORMAT_DATA_KEY, $attributes === [] ? [] : ["attributes" => $attributes]);

        $textAlign = $this->ownStyleProperties($paragraph)["textAlign"] ?? $textAlign;
        $region    = $paragraph->hasAttribute("region") ? $paragraph->getAttribute("region") : $region;
        $cue->setAlignment($this->alignment($region, $textAlign));

        $forced = $this->forcedDisplay($paragraph) ?? $forced ?? $this->regionForcedDisplay($region) ?? false;
        $cue->setForced($forced || $this->hasForcedSpan($paragraph, $forced));

        return $cue;
    }


    /**
     * Reads itts:forcedDisplay from the element or from the styles that it references.
     *
     * @see https://www.w3.org/TR/ttml-imsc1.1/#forceddisplay
     */
    private function forcedDisplay(DOMElement $element, int $depth = 0): ?bool
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === TtmlNamespaces::IMSC_STYLING && $attribute->localName === "forcedDisplay"
                || $attribute->namespaceURI === null && $attribute->nodeName === "itts:forcedDisplay") {
                return trim($attribute->value) === "true";
            }
        }

        $forced = null;
        if ($depth < 20) {
            foreach (preg_split("/\s+/", trim($element->getAttribute("style")), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                if (isset($this->styles[$id])) {
                    $forced = $this->forcedDisplay($this->styles[$id], $depth + 1) ?? $forced;
                }
            }
        }

        return $forced;
    }


    private function regionForcedDisplay(?string $regionId): ?bool
    {
        if ($regionId === null || !isset($this->regions[$regionId])) {
            return null;
        }

        $forced = $this->forcedDisplay($this->regions[$regionId]);
        foreach ($this->regions[$regionId]->childNodes as $child) {
            if ($forced === null && $this->isTtElement($child, "style")) {
                $forced = $this->forcedDisplay($child);
            }
        }

        return $forced;
    }


    private function hasForcedSpan(DOMElement $element, bool $inherited): bool
    {
        foreach ($element->childNodes as $child) {
            if ($this->isTtElement($child, "span")) {
                $forced = $this->forcedDisplay($child) ?? $inherited;
                if ($forced || $this->hasForcedSpan($child, $forced)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * @return array{float, ?float}
     */
    private function interval(DOMElement $element, float $parentBegin, ?float $parentEnd): array
    {
        $begin = $parentBegin;
        if ($element->hasAttribute("begin")) {
            $begin += $this->parseTimeExpression($element->getAttribute("begin"));
        }

        $ends = $parentEnd === null ? [] : [$parentEnd];
        if ($element->hasAttribute("end")) {
            $ends[] = $parentBegin + $this->parseTimeExpression($element->getAttribute("end"));
        }
        if ($element->hasAttribute("dur")) {
            $ends[] = $begin + $this->parseTimeExpression($element->getAttribute("dur"));
        }

        return [$begin, $ends === [] ? null : min($ends)];
    }


    /**
     * A run with null text marks a line break.
     */
    private function collectRuns(DOMNode $node, array $style, ?string $agent, bool $preserveSpace, array &$runs): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text  = str_replace(["\r\n", "\r"], "\n", $child->nodeValue);
                $parts = $preserveSpace ? explode("\n", $text) : [$text];
                foreach ($parts as $idx => $part) {
                    if ($idx > 0) {
                        $runs[] = ["text" => null];
                    }
                    $runs[] = ["text" => preg_replace("/[ \t\n]+/", " ", $part), "style" => $style, "agent" => $agent];
                }
            } elseif ($this->isTtElement($child, "br")) {
                $runs[] = ["text" => null];
            } elseif ($this->isTtElement($child, "span")) {
                $this->collectRuns(
                    $child,
                    $this->resolveStyle($child, $style),
                    $this->agentName($child) ?? $agent,
                    $this->preservesSpace($child, $preserveSpace),
                    $runs
                );
            }
        }
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#content-attribute-space
     */
    private function runsToLines(array $runs): array
    {
        $lines       = [];
        $lineRuns    = [];
        $openAgent   = null;
        foreach ([...$runs, ["text" => null]] as $run) {
            if ($run["text"] !== null) {
                $lineRuns[] = $run;
                continue;
            }

            $endsWithSpace = true;
            foreach ($lineRuns as $idx => $lineRun) {
                if ($endsWithSpace && str_starts_with($lineRun["text"], " ")) {
                    $lineRuns[$idx]["text"] = substr($lineRun["text"], 1);
                }
                if ($lineRuns[$idx]["text"] !== "") {
                    $endsWithSpace = str_ends_with($lineRuns[$idx]["text"], " ");
                }
            }
            for ($idx = count($lineRuns) - 1; $idx >= 0; $idx--) {
                $lineRuns[$idx]["text"] = rtrim($lineRuns[$idx]["text"], " ");
                if ($lineRuns[$idx]["text"] !== "") {
                    break;
                }
            }

            $lines[]  = $this->runsToMarkup($lineRuns, $openAgent);
            $lineRuns = [];
        }

        return $lines;
    }


    /**
     * A speaker tag stays open across lines, as in WebVTT.
     */
    private function runsToMarkup(array $runs, ?string &$openAgent): string
    {
        $markup = "";
        $stack  = [];
        foreach ($runs as $run) {
            if ($run["text"] === "") {
                continue;
            }
            if (trim($run["text"]) === "") {
                $markup .= $run["text"];
                continue;
            }

            if ($run["agent"] !== $openAgent) {
                $markup .= $this->closeTags($stack, 0);
                $stack   = [];
                $markup .= $openAgent === null ? "" : "</v>";
                $markup .= $run["agent"] === null ? "" : "<v " . htmlspecialchars($run["agent"], ENT_NOQUOTES) . ">";
                $openAgent = $run["agent"];
            }

            $wanted = $this->styleToTags($run["style"]);
            $common = 0;
            while ($common < count($stack) && $common < count($wanted) && $stack[$common] === $wanted[$common]) {
                $common++;
            }
            $markup .= $this->closeTags($stack, $common);
            foreach (array_slice($wanted, $common) as $tag) {
                $markup .= $tag;
            }
            $stack   = $wanted;
            $markup .= htmlspecialchars($run["text"], ENT_NOQUOTES);
        }

        return $markup . $this->closeTags($stack, 0);
    }


    private function closeTags(array $stack, int $keep): string
    {
        $markup = "";
        for ($idx = count($stack) - 1; $idx >= $keep; $idx--) {
            $markup .= "</" . substr(strtok($stack[$idx], " >"), 1) . ">";
        }

        return $markup;
    }


    private function styleToTags(array $style): array
    {
        $tags = [];
        if ($style["color"] !== null) {
            $tags[] = "<font color=\"{$style["color"]}\">";
        }
        foreach (["b", "i", "u", "s"] as $tag) {
            if ($style[$tag]) {
                $tags[] = "<$tag>";
            }
        }

        return $tags;
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#semantics-style-resolution-processing-sss
     */
    private function resolveStyle(DOMElement $element, array $inherited): array
    {
        $style = $inherited;
        foreach ($this->ownStyleProperties($element) as $name => $value) {
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
                    $color          = $this->normalizeColor($value);
                    // White is the default text color of every player, so it adds no markup.
                    $style["color"] = $color === "#ffffff" ? null : $color;
                    break;
            }
        }

        return $style;
    }


    private function ownStyleProperties(DOMElement $element, int $depth = 0): array
    {
        $properties = [];
        if ($depth < 20) {
            foreach (preg_split("/\s+/", trim($element->getAttribute("style")), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                if (isset($this->styles[$id])) {
                    $properties = [...$properties, ...$this->ownStyleProperties($this->styles[$id], $depth + 1)];
                }
            }
        }

        return [...$properties, ...$this->stylingAttributes($element)];
    }


    private function stylingAttributes(DOMElement $element): array
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
    private function normalizeColor(string $color): ?string
    {
        $color = strtolower(trim($color));
        if (preg_match("/^#([0-9a-f]{6})([0-9a-f]{2})?$/", $color, $matches)) {
            return "#" . $matches[1];
        }
        if (preg_match("/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*\d{1,3}\s*)?\)$/", $color, $matches)) {
            return sprintf("#%02x%02x%02x", min(255, (int) $matches[1]), min(255, (int) $matches[2]), min(255, (int) $matches[3]));
        }

        return self::NAMED_COLORS[$color] ?? null;
    }


    /**
     * Start and end depend on the text direction, so they give no alignment.
     *
     * @see https://www.w3.org/TR/ttml2/#style-attribute-displayAlign
     */
    private function alignment(?string $regionId, ?string $textAlign): ?int
    {
        if ($regionId !== null && !isset($this->regions[$regionId]) || $regionId === null && $this->regions !== []) {
            return null;
        }

        $properties = [];
        if ($regionId !== null) {
            $region     = $this->regions[$regionId];
            $properties = $this->ownStyleProperties($region);
            foreach ($region->childNodes as $child) {
                if ($this->isTtElement($child, "style")) {
                    $properties = [...$properties, ...$this->ownStyleProperties($child)];
                }
            }
            $properties = [...$properties, ...$this->stylingAttributes($region)];
        }

        $column = match (trim($textAlign ?? $properties["textAlign"] ?? "start")) {
            "left"   => 1,
            "center" => 2,
            "right"  => 3,
            default  => null,
        };
        $origin = $this->verticalLength($properties["origin"] ?? "auto", 0.0);
        $extent = $this->verticalLength($properties["extent"] ?? "auto", 1.0);
        if ($column === null || $origin === null || $extent === null) {
            return null;
        }

        $anchor = match (trim($properties["displayAlign"] ?? "before")) {
            "before" => $origin,
            "center" => $origin + $extent / 2,
            "after"  => $origin + $extent,
            default  => null,
        };

        return match (true) {
            $anchor === null       => null,
            $anchor < 1 / 3        => 6 + $column,
            $anchor > 2 / 3        => $column,
            default                => 3 + $column,
        };
    }


    /**
     * Returns the second length of an origin or extent value as a fraction of the root container height.
     */
    private function verticalLength(string $value, float $auto): ?float
    {
        $parts = preg_split("/\s+/", trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === ["auto"]) {
            return $auto;
        }
        if (count($parts) !== 2 || !preg_match("/^([+-]?\d+(?:\.\d+)?)(%|px|c)$/", $parts[1], $matches)) {
            return null;
        }

        $length = (float) $matches[1];
        if ($matches[2] === "%") {
            return $length / 100;
        }
        if ($matches[2] === "c") {
            $resolution = preg_split("/\s+/", trim($this->attribute($this->root, "ttp", "cellResolution") ?? "32 15"));

            return count($resolution) === 2 && (int) $resolution[1] > 0 ? $length / (int) $resolution[1] : null;
        }

        $body       = $this->firstChild($this->root, "body");
        $rootExtent = $this->stylingAttributes($this->root)["extent"]
                      ?? ($body === null ? null : $this->stylingAttributes($body)["extent"] ?? null);
        if ($rootExtent === null || !preg_match("/^\s*\d+(?:\.\d+)?px\s+(\d+(?:\.\d+)?)px\s*$/", $rootExtent, $matches)
            || (float) $matches[1] <= 0) {
            return null;
        }

        return $length / (float) $matches[1];
    }


    private function agentName(DOMElement $element): ?string
    {
        $ids = preg_split("/\s+/", trim($this->attribute($element, "ttm", "agent") ?? ""), -1, PREG_SPLIT_NO_EMPTY);
        if ($ids === []) {
            return null;
        }

        return $this->agents[$ids[0]] ?? $ids[0];
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent
     */
    private function readAgents(DOMElement $head): array
    {
        $agents = [];
        foreach ($head->getElementsByTagName("*") as $element) {
            if (!$this->isMetadataElement($element, "agent")) {
                continue;
            }
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && $this->isMetadataElement($child, "name") && trim($child->textContent) !== "") {
                    $agents[$id] = trim($child->textContent);
                    break;
                }
            }
        }

        return $agents;
    }


    /**
     * The formatter writes the title metadata in place of the removed element.
     */
    private function removeTitle(DOMElement $head): ?string
    {
        foreach ($head->getElementsByTagName("*") as $element) {
            if ($this->isMetadataElement($element, "title")) {
                $title    = trim($element->textContent);
                $previous = $element->previousSibling;
                if ($previous !== null && $previous->nodeType === XML_TEXT_NODE && trim($previous->nodeValue) === "") {
                    $previous->parentNode->removeChild($previous);
                }
                $element->parentNode->removeChild($element);

                return $title === "" ? null : $title;
            }
        }

        return null;
    }


    private function readNamespaces(DOMDocument $document): array
    {
        $namespaces = [];
        foreach ((new DOMXPath($document))->query("namespace::*", $this->root) as $namespace) {
            if ($namespace->prefix !== "xml") {
                $namespaces[$namespace->prefix ?? ""] = $namespace->namespaceURI;
            }
        }
        ksort($namespaces);

        return $namespaces;
    }


    private function readAttributes(DOMElement $element, array $skip): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $name = $attribute->namespaceURI === TtmlNamespaces::XML ? "xml:" . $attribute->localName : $attribute->nodeName;
            if (in_array($attribute->namespaceURI, TtmlNamespaces::METADATA, true) && $attribute->localName === "agent") {
                continue;
            }
            if (!in_array($name, $skip, true)) {
                $attributes[$name] = $attribute->value;
            }
        }

        return $attributes;
    }


    private function preservesSpace(DOMElement $element, bool $inherited): bool
    {
        $space = $element->getAttributeNS(TtmlNamespaces::XML, "space");

        return $space === "" ? $inherited : $space === "preserve";
    }


    /**
     * Reads a ttp:, tts: or ttm: attribute. A file that does not declare the prefix still gets its value.
     */
    private function attribute(DOMElement $element, string $prefix, string $localName): ?string
    {
        $namespaces = match ($prefix) {
            "ttp" => TtmlNamespaces::PARAMETER,
            "tts" => TtmlNamespaces::STYLING,
            "ttm" => TtmlNamespaces::METADATA,
        };
        foreach ($element->attributes as $attribute) {
            if (in_array($attribute->namespaceURI, $namespaces, true) && $attribute->localName === $localName
                || $attribute->namespaceURI === null && $attribute->nodeName === "$prefix:$localName") {
                return $attribute->value;
            }
        }

        return null;
    }


    private function isTtElement(DOMNode $node, string $localName): bool
    {
        return $node instanceof DOMElement && $node->localName === $localName && $node->namespaceURI === $this->namespace;
    }


    private function isMetadataElement(DOMElement $element, string $localName): bool
    {
        return in_array($element->namespaceURI, TtmlNamespaces::METADATA, true) && $element->localName === $localName
               || $element->namespaceURI === null && $element->nodeName === "ttm:$localName";
    }


    private function firstChild(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($this->isTtElement($child, $localName)) {
                return $child;
            }
        }

        return null;
    }


    /**
     * @return array<string, DOMElement>
     */
    private function elementsById(DOMElement $head, string $localName): array
    {
        $elements = [];
        foreach ($head->getElementsByTagName("*") as $element) {
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            if ($this->isTtElement($element, $localName) && $id !== "" && !isset($elements[$id])) {
                $elements[$id] = $element;
            }
        }

        return $elements;
    }
}
