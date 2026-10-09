<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\XmlLoader;

final class TtmlParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Ttml->value;

    private const TIMING_ATTRIBUTES = ["begin", "end", "dur"];

    // Styles can refer to each other in a loop, so the parser follows at most 20 references.
    private const MAX_STYLE_DEPTH = 20;

    private ?string $namespace;

    private DOMElement $root;

    private TtmlStyles $styles;

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


    protected function read(string $content): Subtitle
    {
        $this->paragraphIndex = 0;
        $document             = $this->loadDocument($content);
        $this->root           = $document->documentElement;
        $this->namespace      = $this->root->namespaceURI;
        if ($this->root->localName !== "tt"
            || !in_array($this->namespace, [TtmlNamespaces::TTML, TtmlNamespaces::DFXP, TtmlNamespaces::DFXP_2006_04, null], true)) {
            throw new ParsingException("The root element is not a TTML <tt> element.");
        }

        $this->readTimingParameters();
        $head = $this->firstChild($this->root, "head");
        $body = $this->firstChild($this->root, "body");
        $this->styles = new TtmlStyles($this->namespace, $head);
        $this->agents = $head === null ? [] : $this->readAgents($head);

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
            $this->readContainer($cues, $body, new TtmlScope(), []);
        }

        return $subtitle->addCues($cues);
    }


    private function parseTimeExpression(string $expression): float
    {
        return self::boundedTime($this->unboundedTimeExpression($expression), trim($expression), null);
    }


    /**
     * @see https://www.w3.org/TR/ttml2/#timing-value-time-expression
     */
    private function unboundedTimeExpression(string $expression): float
    {
        $expression = trim($expression);
        // Some tools write a comma as decimal separator, for example 00:00:01,500.
        if (preg_match("/^(\d{2,}):(\d{2}):(\d{2})(?:[.,](\d+)|:(\d{2,})(?:\.(\d+))?)?$/", $expression, $matches)) {
            $seconds = Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4] ?? "");
            if (($matches[4] ?? "") !== "") {
                return $seconds;
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

        throw new ParsingException("The time expression \"$expression\" is not valid.");
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
            throw new ParsingException("The file is empty.");
        }

        return XmlLoader::xml($xml) ?? throw new ParsingException("The file is not well-formed XML.");
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
     * @param list<SubtitleCue> $cues
     *
     * @see https://www.w3.org/TR/ttml2/#timing-time-intervals
     */
    private function readContainer(array &$cues, DOMElement $container, TtmlScope $parent, array $divAttributes): void
    {
        [$begin, $end] = $this->interval($container, $parent->begin, $parent->end);
        $properties    = $this->styles->ownProperties($container);
        $scope         = new TtmlScope(
            $begin,
            $end,
            $container->hasAttribute("region") ? $container->getAttribute("region") : $parent->region,
            $properties["textAlign"] ?? $parent->textAlign,
            $this->preservesSpace($container, $parent->preserveSpace),
            $this->forcedDisplay($container) ?? $parent->forced,
            $properties === [] ? $parent->styleProperties : [...$parent->styleProperties, $properties],
        );
        if ($this->isTtElement($container, "div")) {
            $divAttributes = [
                ...$divAttributes,
                ...$this->readAttributes($container, [...self::TIMING_ATTRIBUTES, "xml:id", "region"]),
            ];
        }

        foreach ($container->childNodes as $child) {
            if ($this->isTtElement($child, "div")) {
                $this->readContainer($cues, $child, $scope, $divAttributes);
            } elseif ($this->isTtElement($child, "p")) {
                try {
                    $cue = $this->readParagraph($child, $scope);
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


    private function readParagraph(DOMElement $paragraph, TtmlScope $scope): SubtitleCue
    {
        [$begin, $end] = $this->interval($paragraph, $scope->begin, $scope->end);

        $region = $paragraph->hasAttribute("region") ? $paragraph->getAttribute("region") : $scope->region;
        $style  = TtmlStyles::DEFAULT_STYLE;
        foreach ([$this->styles->regionProperties($region), ...$scope->styleProperties] as $properties) {
            $style = TtmlStyles::apply($style, $properties);
        }
        $style = $this->resolveStyle($paragraph, $style);
        $agent = $this->agentName($paragraph);
        $runs  = [];
        $this->collectRuns($paragraph, $style, $agent, $this->preservesSpace($paragraph, $scope->preserveSpace), [$begin, $end], $runs);
        if ($end === null) {
            [$begin, $end] = $this->timedSpansInterval($runs)
                ?? throw new ParsingException("The paragraph that begins at {$begin}s has no end time.");
        }
        $runs = array_values(array_filter(
            $runs,
            fn (array $run): bool => !isset($run["begin"]) || $run["begin"] > $begin && $run["begin"] < $end
        ));

        $cue = new SubtitleCue($begin, $end, $this->runsToLines($runs));
        $id  = $paragraph->getAttributeNS(TtmlNamespaces::XML, "id");
        $cue->setIdentifier($id === "" ? null : $id);

        $attributes = $this->readAttributes($paragraph, [...self::TIMING_ATTRIBUTES, "xml:id"]);
        if (!$paragraph->hasAttribute("region") && $scope->region !== null) {
            $attributes["region"] = $scope->region;
        }
        $cue->setFormatData(self::FORMAT_DATA_KEY, $attributes === [] ? [] : ["attributes" => $attributes]);

        $textAlign = $this->styles->ownProperties($paragraph)["textAlign"] ?? $scope->textAlign;
        $cue->setAlignment($this->alignment($region, $textAlign));

        $forced = $this->forcedDisplay($paragraph) ?? $scope->forced ?? $this->regionForcedDisplay($region) ?? false;
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
        if ($depth < self::MAX_STYLE_DEPTH) {
            foreach ($this->styles->styleIds($element) as $id) {
                if (isset($this->styles->styles[$id])) {
                    $forced = $this->forcedDisplay($this->styles->styles[$id], $depth + 1) ?? $forced;
                }
            }
        }

        return $forced;
    }


    private function regionForcedDisplay(?string $regionId): ?bool
    {
        if ($regionId === null || !isset($this->styles->regions[$regionId])) {
            return null;
        }

        $forced = $this->forcedDisplay($this->styles->regions[$regionId]);
        foreach ($this->styles->regions[$regionId]->childNodes as $child) {
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


    private function offsetTime(float $base, DOMElement $element, string $attribute): float
    {
        $expression = $element->getAttribute($attribute);

        return self::boundedTime($base + $this->parseTimeExpression($expression), trim($expression), $element->getLineNo());
    }


    /**
     * @return array{float, ?float}
     */
    private function interval(DOMElement $element, float $parentBegin, ?float $parentEnd): array
    {
        $begin = $parentBegin;
        if ($element->hasAttribute("begin")) {
            $begin = $this->offsetTime($begin, $element, "begin");
        }

        $ends = $parentEnd === null ? [] : [$parentEnd];
        if ($element->hasAttribute("end")) {
            $ends[] = $this->offsetTime($parentBegin, $element, "end");
        }
        if ($element->hasAttribute("dur")) {
            $ends[] = $this->offsetTime($begin, $element, "dur");
        }

        return [$begin, $ends === [] ? null : min($ends)];
    }


    /**
     * A run with null text marks a line break. A run with a begin marks the start of a timed span.
     *
     * @param array{float, ?float} $interval the time interval of $node
     */
    private function collectRuns(DOMNode $node, array $style, ?string $agent, bool $preserveSpace, array $interval, array &$runs): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                self::addTextRuns($runs, $child->nodeValue, $style, $agent, $preserveSpace);
            } elseif ($this->isTtElement($child, "br")) {
                $runs[] = ["text" => null];
            } elseif ($this->isTtElement($child, "span")) {
                $spanInterval = $interval;
                if (array_filter(self::TIMING_ATTRIBUTES, $child->hasAttribute(...)) !== []) {
                    try {
                        $spanInterval = $this->interval($child, ...$interval);
                        $runs[]       = ["text" => "", "begin" => $spanInterval[0], "end" => $spanInterval[1]];
                    } catch (ParsingException) {
                        // A broken span time only drops the word timestamp, so the text of the cue stays.
                    }
                }
                $this->collectRuns(
                    $child,
                    $this->resolveStyle($child, $style),
                    $this->agentName($child) ?? $agent,
                    $this->preservesSpace($child, $preserveSpace),
                    $spanInterval,
                    $runs
                );
            }
        }
    }


    /**
     * Returns the union of the timed spans, for a paragraph that has no end of its own.
     *
     * @return ?array{float, float}
     */
    private function timedSpansInterval(array $runs): ?array
    {
        $timed = array_filter($runs, fn (array $run): bool => isset($run["begin"]));
        $ends  = array_filter(array_column($timed, "end"), fn (?float $end): bool => $end !== null);
        if ($ends === []) {
            return null;
        }

        return [min(array_column($timed, "begin")), max($ends)];
    }


    /**
     * Adds the runs of a text node. With xml:space="preserve", each line feed becomes a line break.
     */
    private static function addTextRuns(array &$runs, string $text, array $style, ?string $agent, bool $preserveSpace): void
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = $preserveSpace ? explode("\n", $text) : [$text];
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $runs[] = ["text" => null];
            }
            $runs[] = ["text" => preg_replace("/[ \t\n]+/", " ", $part), "style" => $style, "agent" => $agent];
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
            foreach ($lineRuns as $index => $lineRun) {
                if ($endsWithSpace && str_starts_with($lineRun["text"], " ")) {
                    $lineRuns[$index]["text"] = substr($lineRun["text"], 1);
                }
                if ($lineRuns[$index]["text"] !== "") {
                    $endsWithSpace = str_ends_with($lineRuns[$index]["text"], " ");
                }
            }
            for ($index = count($lineRuns) - 1; $index >= 0; $index--) {
                $lineRuns[$index]["text"] = rtrim($lineRuns[$index]["text"], " ");
                if ($lineRuns[$index]["text"] !== "") {
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
        $markup    = "";
        $agentRuns = [];
        foreach ($runs as $run) {
            if (isset($run["begin"])) {
                $markup   .= StyleRuns::toMarkup($agentRuns) . "<" . Markup::coreTimestamp($run["begin"]) . ">";
                $agentRuns = [];
                continue;
            }
            if (trim($run["text"]) !== "" && $run["agent"] !== $openAgent) {
                $markup   .= StyleRuns::toMarkup($agentRuns) . ($openAgent === null ? "" : "</v>")
                    . ($run["agent"] === null ? "" : Markup::voiceTag($run["agent"]));
                $agentRuns = [];
                $openAgent = $run["agent"];
            }
            $agentRuns[] = [$run["text"], $run["style"]];
        }

        return $markup . StyleRuns::toMarkup($agentRuns);
    }


    private function resolveStyle(DOMElement $element, array $inherited): array
    {
        return TtmlStyles::apply($inherited, $this->styles->ownProperties($element));
    }


    /**
     * Start and end depend on the text direction, so they give no alignment.
     *
     * @see https://www.w3.org/TR/ttml2/#style-attribute-displayAlign
     */
    private function alignment(?string $regionId, ?string $textAlign): ?int
    {
        if ($regionId !== null && !isset($this->styles->regions[$regionId]) || $regionId === null && $this->styles->regions !== []) {
            return null;
        }

        $properties = $this->styles->regionProperties($regionId);
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
        $rootExtent = TtmlStyles::stylingAttributes($this->root)["extent"]
                      ?? ($body === null ? null : TtmlStyles::stylingAttributes($body)["extent"] ?? null);
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
}
