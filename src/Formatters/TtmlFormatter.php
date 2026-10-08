<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMElement;
use DOMNode;
use SubtitleToolbox\Exceptions\UnwritableContentException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\TtmlNamespaces;
use SubtitleToolbox\Parsers\TtmlParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;
use SubtitleToolbox\XmlLoader;

final class TtmlFormatter extends SubtitleFormatter
{
    // The formatter writes media times, so these parameters no longer apply.
    private const SKIPPED_ROOT_PARAMETERS = ["timeBase", "clockMode", "dropMode", "markerMode"];

    private const REGION_NAMES = [
        1 => "bottomLeft", 2 => "bottomCenter", 3 => "bottomRight",
        4 => "middleLeft", 5 => "middleCenter", 6 => "middleRight",
        7 => "topLeft", 8 => "topCenter", 9 => "topRight",
    ];

    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData = $subtitle->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $context  = $this->createContext(
            ($fileData["namespace"] ?? "") ?: TtmlNamespaces::TTML,
            $fileData["namespaces"] ?? [],
            $fileData["head"] ?? "<head/>"
        );

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            $element = $context->headDocument->createElementNS($context->namespaces[$context->ttm], "$context->ttm:title");
            $element->appendChild($context->headDocument->createTextNode($title));
            $this->insertChild($context->head, $element, $context->head->firstChild, 2);
        }

        // Regions and agents go into the head while the paragraphs are formatted, so the IDs of the body come later.
        $divs = [];
        foreach ($subtitle->getCues() as $cue) {
            $div       = $this->writableAttributes($context, $cue->findFormatData(TtmlParser::FORMAT_DATA_KEY)["div"] ?? [], []);
            $paragraph = [$this->paragraphId($cue), $this->formatParagraph($context, $cue, $options, $fileData === [])];
            if ($divs !== [] && $divs[count($divs) - 1]["attributes"] === $div) {
                $divs[count($divs) - 1]["paragraphs"][] = $paragraph;
            } else {
                $divs[] = ["attributes" => $div, "paragraphs" => [$paragraph]];
            }
        }

        $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" . LineEnding::Lf->value
                  . "<tt" . $this->formatRootAttributes($context, $subtitle, $fileData["attributes"] ?? []) . ">" . LineEnding::Lf->value
                  . "  " . $context->headDocument->saveXML($context->head) . LineEnding::Lf->value
                  . "  <body" . $this->formatAttributes($context, $fileData["body"] ?? [], []) . ">" . LineEnding::Lf->value;
        if ($divs === []) {
            $output .= "    <div/>" . LineEnding::Lf->value;
        }
        foreach ($divs as $div) {
            $output .= "    <div" . $this->formatWritableAttributes($context, $div["attributes"]) . ">" . LineEnding::Lf->value;
            foreach ($div["paragraphs"] as [$id, $paragraph]) {
                $idAttribute = $id === null ? "" : $this->formatAttribute("xml:id", $this->unusedId($context, $id));
                $output     .= "      <p$idAttribute$paragraph" . LineEnding::Lf->value;
            }
            $output .= "    </div>" . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions($output . "  </body>" . LineEnding::Lf->value . "</tt>" . LineEnding::Lf->value, $options);
    }


    private function createContext(string $namespace, array $stored, string $headXml): TtmlContext
    {
        $namespaces = ["" => $namespace];
        foreach ($stored as $prefix => $uri) {
            if ($prefix !== "") {
                $namespaces[$prefix] = $uri;
            }
        }

        $isDfxp = $namespace === TtmlNamespaces::DFXP;
        $tts    = $this->bindPrefix($namespaces, "tts", TtmlNamespaces::STYLING, $isDfxp ? 1 : 0);
        $ttm    = $this->bindPrefix($namespaces, "ttm", TtmlNamespaces::METADATA, $isDfxp ? 1 : 0);
        ksort($namespaces);

        $declarations = $this->formatNamespaceDeclarations($namespaces);
        $document     = XmlLoader::xml("<tt$declarations>$headXml</tt>");
        $head         = $document?->documentElement->firstChild;
        if (!$head instanceof DOMElement || $head->localName !== "head") {
            throw new UnwritableContentException("The stored TTML head is not a well-formed <head> element.");
        }

        $context = new TtmlContext($namespace, $namespaces, $tts, $ttm, $document, $head);
        foreach ($head->getElementsByTagName("*") as $element) {
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            if ($id !== "") {
                $context->usedIds[$id] = true;
            }
            if ($element->localName === "region" && $element->namespaceURI === $namespace
                && $element->hasAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) {
                $context->forcedRegions[$id] ??= trim($element->getAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) === "true";
            }
            if ($element->localName === "agent" && in_array($element->namespaceURI, TtmlNamespaces::METADATA, true)) {
                foreach ($element->getElementsByTagNameNS($element->namespaceURI, "name") as $name) {
                    $context->agentIds[trim($name->textContent)] ??= $id;
                }
            }
        }

        return $context;
    }


    private function bindPrefix(array &$namespaces, string $prefix, array $uris, int $preferred): string
    {
        foreach ($namespaces as $boundPrefix => $uri) {
            if ($boundPrefix !== "" && in_array($uri, $uris, true)) {
                return $boundPrefix;
            }
        }

        $candidate = $prefix;
        for ($idx = 2; isset($namespaces[$candidate]); $idx++) {
            $candidate = $prefix . $idx;
        }
        $namespaces[$candidate] = $uris[$preferred];

        return $candidate;
    }


    private function formatRootAttributes(TtmlContext $context, Subtitle $subtitle, array $attributes): string
    {
        $output  = $this->formatNamespaceDeclarations($context->namespaces);
        $output .= $this->formatAttribute("xml:lang", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE) ?? "");

        foreach ($attributes as $name => $value) {
            [$prefix, $localName] = $this->splitName($name);
            $isParameter = in_array($context->namespaces[$prefix] ?? null, TtmlNamespaces::PARAMETER, true);
            if ($this->isWritable($context, $name) && !($isParameter && in_array($localName, self::SKIPPED_ROOT_PARAMETERS, true))) {
                $output .= $this->formatAttribute($name, $name === "xml:id" ? $this->unusedId($context, $value) : $value);
            }
        }

        return $output;
    }


    private function paragraphId(SubtitleCue $cue): ?string
    {
        $identifier = $cue->getIdentifier();

        return $identifier !== null && preg_match("/^[A-Za-z_][\w.-]*$/", $identifier) ? $identifier : null;
    }


    /**
     * Returns the paragraph without "<p" and without xml:id, so that the caller adds the ID in output order.
     */
    private function formatParagraph(TtmlContext $context, SubtitleCue $cue, WriteOptions $options, bool $isForeignSubtitle): string
    {
        $attributes  = $this->formatAttribute("begin", Markup::coreTimestamp($cue->getStart()));
        $attributes .= $this->formatAttribute("end", Markup::coreTimestamp($cue->getEnd()));

        $cueData     = $cue->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $stored      = $cueData["attributes"] ?? [];
        $forcedName  = $this->forcedDisplayName($context, $stored);
        $forced      = $forcedName === null
            ? $this->forcedDisplay($context, $cueData["div"] ?? []) ?? $context->forcedRegions[$stored["region"] ?? ""] ?? false
            : trim($stored[$forcedName]) === "true";
        if ($forced !== $cue->isForced() && $forcedName !== null) {
            $stored[$forcedName] = $cue->isForced() ? "true" : "false";
        }
        $attributes .= $this->formatAttributes($context, $stored, ["xml:id", "begin", "end", "dur"]);
        if (!isset($stored["region"]) && ($cue->getAlignment() !== null || $isForeignSubtitle)) {
            $attributes .= $this->formatAttribute("region", $this->regionId($context, $cue->getAlignment() ?? SubtitleCue::DEFAULT_ALIGNMENT));
        }
        if ($forced !== $cue->isForced() && $forcedName === null) {
            $attributes .= $this->formatAttribute($this->ittsPrefix($context) . ":forcedDisplay", $cue->isForced() ? "true" : "false");
        }

        $text = implode(LineEnding::Lf->value, $cue->getLines());
        if ($options->stripTags) {
            return "$attributes>" . $this->formatText(Markup::stripAllTags($text)) . "</p>";
        }

        [$agent, $content] = $this->markupToTtml($context, $text);
        if ($agent !== null) {
            $attributes .= $this->formatAttribute("$context->ttm:agent", $this->agentId($context, $agent));
        }

        return "$attributes>$content</p>";
    }


    /**
     * Returns the name of the stored itts:forcedDisplay attribute, or null when the attributes do not hold it.
     */
    private function forcedDisplayName(TtmlContext $context, array $attributes): ?string
    {
        foreach (array_keys($attributes) as $name) {
            [$prefix, $localName] = $this->splitName($name);
            if ($localName === "forcedDisplay" && $prefix !== ""
                && ($context->namespaces[$prefix] ?? null) === TtmlNamespaces::IMSC_STYLING) {
                return $name;
            }
        }

        return null;
    }


    private function forcedDisplay(TtmlContext $context, array $attributes): ?bool
    {
        $name = $this->forcedDisplayName($context, $attributes);

        return $name === null ? null : trim($attributes[$name]) === "true";
    }


    /**
     * Declares the IMSC styling namespace on the root element when the input file did not.
     */
    private function ittsPrefix(TtmlContext $context): string
    {
        $prefix = $this->bindPrefix($context->namespaces, "itts", [TtmlNamespaces::IMSC_STYLING], 0);
        ksort($context->namespaces);

        return $prefix;
    }


    /**
     * Returns the speaker of the whole cue separately, so that it goes on the paragraph.
     *
     * @return array{?string, string}
     */
    private function markupToTtml(TtmlContext $context, string $text): array
    {
        $tokens = Markup::splitTags($text);
        $agent  = null;
        if (preg_match("/^" . Markup::VOICE_TAG . "$/", $tokens[1] ?? "", $matches) && trim($tokens[0]) === ""
            && preg_match_all("/<\/?v[\s.>]/", $text) === 1) {
            $agent = Markup::decodeEntities(trim($matches[2]));
            $tokens = array_slice($tokens, 2);
        }

        $output = "";
        $stack  = [];
        foreach ($tokens as $idx => $token) {
            if ($idx % 2 === 0) {
                $output .= $this->formatText($token);
                continue;
            }

            if (preg_match("/^<\/([a-zA-Z]+)\s*>$/", $token, $matches)) {
                $output .= $this->closeSpan($stack, strtolower($matches[1]));
                continue;
            }
            if (!preg_match("/^<([a-zA-Z]+)([\s.][^>]*)?>$/", $token, $matches)) {
                continue;
            }

            $tag = strtolower($matches[1]);
            if ($tag === "v") {
                $output .= $this->closeSpan($stack, "v");
            }
            $span = $this->openSpan($context, $tag, $matches[2] ?? "");
            if ($span !== null) {
                $stack[] = ["tag" => $tag, "span" => $span];
                $output .= $span;
            }
        }

        while ($stack !== []) {
            $output .= array_pop($stack)["span"] === "" ? "" : "</span>";
        }

        return [$agent, $output];
    }


    /**
     * Returns the opening span of a core markup tag, an empty string for a tag without TTML style, or null for
     * a tag outside the core markup.
     */
    private function openSpan(TtmlContext $context, string $tag, string $rest): ?string
    {
        $style = match ($tag) {
            "b"     => ["fontWeight", "bold"],
            "i"     => ["fontStyle", "italic"],
            "u"     => ["textDecoration", "underline"],
            "s"     => ["textDecoration", "lineThrough"],
            default => null,
        };
        if ($style !== null) {
            return "<span" . $this->formatAttribute("$context->tts:$style[0]", $style[1]) . ">";
        }

        if ($tag === "font") {
            $color = Markup::decodeEntities(trim(Markup::fontColor($rest) ?? ""));

            return $color === "" ? "" : "<span" . $this->formatAttribute("$context->tts:color", $color) . ">";
        }

        if ($tag === "v") {
            $name = Markup::decodeEntities(trim(preg_replace("/^\.[^\s]*/", "", $rest)));

            return $name === "" ? "" : "<span" . $this->formatAttribute("$context->ttm:agent", $this->agentId($context, $name)) . ">";
        }

        return null;
    }


    /**
     * Reopens the spans above the closed tag, so that mis-nested core markup keeps its styles.
     */
    private function closeSpan(array &$stack, string $tag): string
    {
        $position = null;
        foreach ($stack as $idx => $entry) {
            if ($entry["tag"] === $tag) {
                $position = $idx;
            }
        }
        if ($position === null) {
            return "";
        }

        $output = "";
        $above  = array_slice($stack, $position + 1);
        for ($idx = count($stack) - 1; $idx >= $position; $idx--) {
            $output .= $stack[$idx]["span"] === "" ? "" : "</span>";
        }
        $stack = [...array_slice($stack, 0, $position), ...$above];
        foreach ($above as $entry) {
            $output .= $entry["span"];
        }

        return $output;
    }


    private function formatText(string $text): string
    {
        $text = htmlspecialchars(Markup::decodeEntities($text), ENT_XML1 | ENT_NOQUOTES, "UTF-8");

        return str_replace(LineEnding::Lf->value, "<br/>", $text);
    }


    private function agentId(TtmlContext $context, string $name): string
    {
        if (isset($context->agentIds[$name])) {
            return $context->agentIds[$name];
        }

        $id      = $this->unusedId($context, "agent" . (count($context->agentIds) + 1));
        $uri     = $context->namespaces[$context->ttm];
        $element = $context->headDocument->createElementNS($uri, "$context->ttm:agent");
        $element->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $element->setAttribute("type", "person");
        $nameElement = $context->headDocument->createElementNS($uri, "$context->ttm:name");
        $nameElement->setAttribute("type", "full");
        $nameElement->appendChild($context->headDocument->createTextNode($name));
        $element->appendChild($nameElement);

        $before = null;
        foreach ($context->head->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, ["styling", "layout"], true)) {
                $before = $child;
                break;
            }
        }
        $this->insertChild($context->head, $element, $before, 2);

        return $context->agentIds[$name] = $id;
    }


    /**
     * Adds a region for the alignment, in the shape of the IMSC 1.1 examples with a safe area of 10%.
     */
    private function regionId(TtmlContext $context, int $alignment): string
    {
        if (isset($context->regionIds[$alignment])) {
            return $context->regionIds[$alignment];
        }

        $layout = null;
        foreach ($context->head->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === "layout" && $child->namespaceURI === $context->namespace) {
                $layout = $child;
            }
        }
        if ($layout === null) {
            $layout = $context->headDocument->createElementNS($context->namespace, "layout");
            $this->insertChild($context->head, $layout, null, 2);
        }

        $id     = $this->unusedId($context, self::REGION_NAMES[$alignment]);
        $region = $context->headDocument->createElementNS($context->namespace, "region");
        $region->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $uri = $context->namespaces[$context->tts];
        $region->setAttributeNS($uri, "$context->tts:origin", "10% 10%");
        $region->setAttributeNS($uri, "$context->tts:extent", "80% 80%");
        $region->setAttributeNS($uri, "$context->tts:displayAlign", ["after", "center", "before"][intdiv($alignment - 1, 3)]);
        $region->setAttributeNS($uri, "$context->tts:textAlign", ["left", "center", "right"][($alignment - 1) % 3]);
        $this->insertChild($layout, $region, null, 3);

        return $context->regionIds[$alignment] = $id;
    }


    private function unusedId(TtmlContext $context, string $id): string
    {
        $candidate = $id;
        for ($idx = 2; isset($context->usedIds[$candidate]); $idx++) {
            $candidate = $id . "_" . $idx;
        }
        $context->usedIds[$candidate] = true;

        return $candidate;
    }


    private function insertChild(DOMElement $parent, DOMElement $element, ?DOMNode $before, int $depth): void
    {
        $document = $parent->ownerDocument;
        if (!$parent->hasChildNodes()) {
            $parent->appendChild($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth - 1)));
        }
        if ($before === null) {
            $last   = $parent->lastChild;
            $before = $last->nodeType === XML_TEXT_NODE && trim($last->nodeValue) === ""
                ? $last
                : $parent->appendChild($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth - 1)));
        } elseif ($before->previousSibling !== null && $before->previousSibling->nodeType === XML_TEXT_NODE
                  && trim($before->previousSibling->nodeValue) === "" && $before !== $parent->firstChild) {
            $before = $before->previousSibling;
        }

        $parent->insertBefore($document->createTextNode(LineEnding::Lf->value . str_repeat("  ", $depth)), $before);
        $parent->insertBefore($element, $before);
    }


    private function formatAttributes(TtmlContext $context, array $attributes, array $skip): string
    {
        return $this->formatWritableAttributes($context, $this->writableAttributes($context, $attributes, $skip));
    }


    private function writableAttributes(TtmlContext $context, array $attributes, array $skip): array
    {
        return array_filter(
            $attributes,
            fn (string $name): bool => $this->isWritable($context, $name) && !in_array($name, $skip, true),
            ARRAY_FILTER_USE_KEY
        );
    }


    private function formatWritableAttributes(TtmlContext $context, array $attributes): string
    {
        $output = "";
        foreach ($attributes as $name => $value) {
            $output .= $this->formatAttribute($name, $name === "xml:id" ? $this->unusedId($context, $value) : $value);
        }

        return $output;
    }


    /**
     * Skips attributes whose prefix the output does not declare, for example a prefix declared on the paragraph.
     */
    private function isWritable(TtmlContext $context, string $name): bool
    {
        if (!str_contains($name, ":")) {
            return true;
        }
        [$prefix] = $this->splitName($name);

        return $prefix === "xml" || ($prefix !== "" && isset($context->namespaces[$prefix]));
    }


    /**
     * @param array<string, string> $namespaces
     */
    private function formatNamespaceDeclarations(array $namespaces): string
    {
        $output = "";
        foreach ($namespaces as $prefix => $uri) {
            $output .= $this->formatAttribute($prefix === "" ? "xmlns" : "xmlns:$prefix", $uri);
        }

        return $output;
    }


    /**
     * @return array{string, string} the prefix, "" for none, and the local name
     */
    private function splitName(string $name): array
    {
        return str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
    }


    private function formatAttribute(string $name, string $value): string
    {
        return " $name=\"" . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, "UTF-8") . "\"";
    }
}
