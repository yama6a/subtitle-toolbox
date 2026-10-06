<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMDocument;
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

final class TtmlFormatter extends SubtitleFormatter
{
    private const NL = LineEnding::Lf->value;

    // The formatter writes media times, so these parameters no longer apply.
    private const SKIPPED_ROOT_PARAMETERS = ["timeBase", "clockMode", "dropMode", "markerMode"];

    private const REGION_NAMES = [
        1 => "bottomLeft", 2 => "bottomCenter", 3 => "bottomRight",
        4 => "middleLeft", 5 => "middleCenter", 6 => "middleRight",
        7 => "topLeft", 8 => "topCenter", 9 => "topRight",
    ];

    private string $namespace;

    /** @var array<string, string> prefix => namespace URI, "" for the default namespace */
    private array $namespaces;

    private string $tts;

    private string $ttm;

    private DOMDocument $headDocument;

    private DOMElement $head;

    /** @var array<string, string> agent name => xml:id */
    private array $agentIds;

    /** @var array<int, string> alignment => region xml:id */
    private array $regionIds;

    /** @var array<string, true> */
    private array $usedIds;

    /** @var array<string, bool> region xml:id => itts:forcedDisplay of the region */
    private array $forcedRegions;


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $fileData        = $subtitle->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $this->namespace = ($fileData["namespace"] ?? "") ?: TtmlNamespaces::TTML;
        $this->prepareNamespaces($fileData["namespaces"] ?? []);
        $this->loadHead($fileData["head"] ?? "<head/>");
        $this->regionIds = [];

        $title = $subtitle->findMetadata(Subtitle::METADATA_TITLE);
        if ($title !== null) {
            $element = $this->headDocument->createElementNS($this->namespaces[$this->ttm], "$this->ttm:title");
            $element->appendChild($this->headDocument->createTextNode($title));
            $this->insertChild($this->head, $element, $this->head->firstChild, 2);
        }

        // Regions and agents go into the head while the paragraphs are formatted, so the IDs of the body come later.
        $divs = [];
        foreach ($subtitle->getCues() as $cue) {
            $div       = $this->writableAttributes($cue->findFormatData(TtmlParser::FORMAT_DATA_KEY)["div"] ?? [], []);
            $paragraph = [$this->paragraphId($cue), $this->formatParagraph($cue, $options, $fileData === [])];
            if ($divs !== [] && $divs[count($divs) - 1]["attributes"] === $div) {
                $divs[count($divs) - 1]["paragraphs"][] = $paragraph;
            } else {
                $divs[] = ["attributes" => $div, "paragraphs" => [$paragraph]];
            }
        }

        $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" . self::NL
                  . "<tt" . $this->formatRootAttributes($subtitle, $fileData["attributes"] ?? []) . ">" . self::NL
                  . "  " . $this->headDocument->saveXML($this->head) . self::NL
                  . "  <body" . $this->formatAttributes($fileData["body"] ?? [], []) . ">" . self::NL;
        if ($divs === []) {
            $output .= "    <div/>" . self::NL;
        }
        foreach ($divs as $div) {
            $output .= "    <div" . $this->formatWritableAttributes($div["attributes"]) . ">" . self::NL;
            foreach ($div["paragraphs"] as [$id, $paragraph]) {
                $idAttribute = $id === null ? "" : $this->formatAttribute("xml:id", $this->unusedId($id));
                $output     .= "      <p$idAttribute$paragraph" . self::NL;
            }
            $output .= "    </div>" . self::NL;
        }

        return $this->applyOutputOptions($output . "  </body>" . self::NL . "</tt>" . self::NL, $options);
    }


    private function prepareNamespaces(array $stored): void
    {
        $namespaces = ["" => $this->namespace];
        foreach ($stored as $prefix => $uri) {
            if ($prefix !== "") {
                $namespaces[$prefix] = $uri;
            }
        }

        $isDfxp    = $this->namespace === TtmlNamespaces::DFXP;
        $this->tts = $this->bindPrefix($namespaces, "tts", TtmlNamespaces::STYLING, $isDfxp ? 1 : 0);
        $this->ttm = $this->bindPrefix($namespaces, "ttm", TtmlNamespaces::METADATA, $isDfxp ? 1 : 0);
        ksort($namespaces);
        $this->namespaces = $namespaces;
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


    private function loadHead(string $headXml): void
    {
        $declarations = "";
        foreach ($this->namespaces as $prefix => $uri) {
            $declarations .= $this->formatAttribute($prefix === "" ? "xmlns" : "xmlns:$prefix", $uri);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = $document->loadXML("<tt$declarations>$headXml</tt>", LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $head = $loaded ? $document->documentElement->firstChild : null;
        if (!$head instanceof DOMElement || $head->localName !== "head") {
            throw new UnwritableContentException("The stored TTML head is not a well-formed <head> element!");
        }

        $this->headDocument  = $document;
        $this->head          = $head;
        $this->agentIds      = [];
        $this->usedIds       = [];
        $this->forcedRegions = [];
        foreach ($head->getElementsByTagName("*") as $element) {
            $id = $element->getAttributeNS(TtmlNamespaces::XML, "id");
            if ($id !== "") {
                $this->usedIds[$id] = true;
            }
            if ($element->localName === "region" && $element->namespaceURI === $this->namespace
                && $element->hasAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) {
                $this->forcedRegions[$id] ??= trim($element->getAttributeNS(TtmlNamespaces::IMSC_STYLING, "forcedDisplay")) === "true";
            }
            if ($element->localName === "agent" && in_array($element->namespaceURI, TtmlNamespaces::METADATA, true)) {
                foreach ($element->getElementsByTagNameNS($element->namespaceURI, "name") as $name) {
                    $this->agentIds[trim($name->textContent)] ??= $id;
                }
            }
        }
    }


    private function formatRootAttributes(Subtitle $subtitle, array $attributes): string
    {
        $output = "";
        foreach ($this->namespaces as $prefix => $uri) {
            $output .= $this->formatAttribute($prefix === "" ? "xmlns" : "xmlns:$prefix", $uri);
        }
        $output .= $this->formatAttribute("xml:lang", $subtitle->findMetadata(Subtitle::METADATA_LANGUAGE) ?? "");

        foreach ($attributes as $name => $value) {
            [$prefix, $localName] = str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
            $isParameter = in_array($this->namespaces[$prefix] ?? null, TtmlNamespaces::PARAMETER, true);
            if ($this->isWritable($name) && !($isParameter && in_array($localName, self::SKIPPED_ROOT_PARAMETERS, true))) {
                $output .= $this->formatAttribute($name, $name === "xml:id" ? $this->unusedId($value) : $value);
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
    private function formatParagraph(SubtitleCue $cue, WriteOptions $options, bool $isForeignSubtitle): string
    {
        $attributes  = $this->formatAttribute("begin", Markup::coreTimestamp($cue->getStart()));
        $attributes .= $this->formatAttribute("end", Markup::coreTimestamp($cue->getEnd()));

        $cueData     = $cue->findFormatData(TtmlParser::FORMAT_DATA_KEY);
        $stored      = $cueData["attributes"] ?? [];
        $forcedName  = $this->forcedDisplayName($stored);
        $forced      = $forcedName === null
            ? $this->forcedDisplay($cueData["div"] ?? []) ?? $this->forcedRegions[$stored["region"] ?? ""] ?? false
            : trim($stored[$forcedName]) === "true";
        if ($forced !== $cue->isForced() && $forcedName !== null) {
            $stored[$forcedName] = $cue->isForced() ? "true" : "false";
        }
        $attributes .= $this->formatAttributes($stored, ["xml:id", "begin", "end", "dur"]);
        if (!isset($stored["region"]) && ($cue->getAlignment() !== null || $isForeignSubtitle)) {
            $attributes .= $this->formatAttribute("region", $this->regionId($cue->getAlignment() ?? 2));
        }
        if ($forced !== $cue->isForced() && $forcedName === null) {
            $attributes .= $this->formatAttribute($this->ittsPrefix() . ":forcedDisplay", $cue->isForced() ? "true" : "false");
        }

        $text = implode(self::NL, $cue->getLines());
        if ($options->stripTags) {
            return "$attributes>" . $this->formatText(Markup::stripAllTags($text)) . "</p>";
        }

        [$agent, $content] = $this->markupToTtml($text);
        if ($agent !== null) {
            $attributes .= $this->formatAttribute("$this->ttm:agent", $this->agentId($agent));
        }

        return "$attributes>$content</p>";
    }


    /**
     * Returns the name of the stored itts:forcedDisplay attribute, or null when the attributes do not hold it.
     */
    private function forcedDisplayName(array $attributes): ?string
    {
        foreach (array_keys($attributes) as $name) {
            [$prefix, $localName] = str_contains($name, ":") ? explode(":", $name, 2) : ["", $name];
            if ($localName === "forcedDisplay" && $prefix !== ""
                && ($this->namespaces[$prefix] ?? null) === TtmlNamespaces::IMSC_STYLING) {
                return $name;
            }
        }

        return null;
    }


    private function forcedDisplay(array $attributes): ?bool
    {
        $name = $this->forcedDisplayName($attributes);

        return $name === null ? null : trim($attributes[$name]) === "true";
    }


    /**
     * Declares the IMSC styling namespace on the root element when the input file did not.
     */
    private function ittsPrefix(): string
    {
        $prefix = $this->bindPrefix($this->namespaces, "itts", [TtmlNamespaces::IMSC_STYLING], 0);
        ksort($this->namespaces);

        return $prefix;
    }


    /**
     * Returns the speaker of the whole cue separately, so that it goes on the paragraph.
     *
     * @return array{?string, string}
     */
    private function markupToTtml(string $text): array
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
            $span = $this->openSpan($tag, $matches[2] ?? "");
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
    private function openSpan(string $tag, string $rest): ?string
    {
        $style = match ($tag) {
            "b"     => ["fontWeight", "bold"],
            "i"     => ["fontStyle", "italic"],
            "u"     => ["textDecoration", "underline"],
            "s"     => ["textDecoration", "lineThrough"],
            default => null,
        };
        if ($style !== null) {
            return "<span" . $this->formatAttribute("$this->tts:$style[0]", $style[1]) . ">";
        }

        if ($tag === "font") {
            $color = Markup::decodeEntities(trim(Markup::fontColor($rest) ?? ""));

            return $color === "" ? "" : "<span" . $this->formatAttribute("$this->tts:color", $color) . ">";
        }

        if ($tag === "v") {
            $name = Markup::decodeEntities(trim(preg_replace("/^\.[^\s]*/", "", $rest)));

            return $name === "" ? "" : "<span" . $this->formatAttribute("$this->ttm:agent", $this->agentId($name)) . ">";
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

        return str_replace(self::NL, "<br/>", $text);
    }


    private function agentId(string $name): string
    {
        if (isset($this->agentIds[$name])) {
            return $this->agentIds[$name];
        }

        $id      = $this->unusedId("agent" . (count($this->agentIds) + 1));
        $uri     = $this->namespaces[$this->ttm];
        $element = $this->headDocument->createElementNS($uri, "$this->ttm:agent");
        $element->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $element->setAttribute("type", "person");
        $nameElement = $this->headDocument->createElementNS($uri, "$this->ttm:name");
        $nameElement->setAttribute("type", "full");
        $nameElement->appendChild($this->headDocument->createTextNode($name));
        $element->appendChild($nameElement);

        $before = null;
        foreach ($this->head->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, ["styling", "layout"], true)) {
                $before = $child;
                break;
            }
        }
        $this->insertChild($this->head, $element, $before, 2);

        return $this->agentIds[$name] = $id;
    }


    /**
     * Adds a region for the alignment, in the shape of the IMSC 1.1 examples with a safe area of 10%.
     */
    private function regionId(int $alignment): string
    {
        if (isset($this->regionIds[$alignment])) {
            return $this->regionIds[$alignment];
        }

        $layout = null;
        foreach ($this->head->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === "layout" && $child->namespaceURI === $this->namespace) {
                $layout = $child;
            }
        }
        if ($layout === null) {
            $layout = $this->headDocument->createElementNS($this->namespace, "layout");
            $this->insertChild($this->head, $layout, null, 2);
        }

        $id     = $this->unusedId(self::REGION_NAMES[$alignment]);
        $region = $this->headDocument->createElementNS($this->namespace, "region");
        $region->setAttributeNS(TtmlNamespaces::XML, "xml:id", $id);
        $uri = $this->namespaces[$this->tts];
        $region->setAttributeNS($uri, "$this->tts:origin", "10% 10%");
        $region->setAttributeNS($uri, "$this->tts:extent", "80% 80%");
        $region->setAttributeNS($uri, "$this->tts:displayAlign", ["after", "center", "before"][intdiv($alignment - 1, 3)]);
        $region->setAttributeNS($uri, "$this->tts:textAlign", ["left", "center", "right"][($alignment - 1) % 3]);
        $this->insertChild($layout, $region, null, 3);

        return $this->regionIds[$alignment] = $id;
    }


    private function unusedId(string $id): string
    {
        $candidate = $id;
        for ($idx = 2; isset($this->usedIds[$candidate]); $idx++) {
            $candidate = $id . "_" . $idx;
        }
        $this->usedIds[$candidate] = true;

        return $candidate;
    }


    private function insertChild(DOMElement $parent, DOMElement $element, ?DOMNode $before, int $depth): void
    {
        $document = $parent->ownerDocument;
        if (!$parent->hasChildNodes()) {
            $parent->appendChild($document->createTextNode(self::NL . str_repeat("  ", $depth - 1)));
        }
        if ($before === null) {
            $last   = $parent->lastChild;
            $before = $last->nodeType === XML_TEXT_NODE && trim($last->nodeValue) === ""
                ? $last
                : $parent->appendChild($document->createTextNode(self::NL . str_repeat("  ", $depth - 1)));
        } elseif ($before->previousSibling !== null && $before->previousSibling->nodeType === XML_TEXT_NODE
                  && trim($before->previousSibling->nodeValue) === "" && $before !== $parent->firstChild) {
            $before = $before->previousSibling;
        }

        $parent->insertBefore($document->createTextNode(self::NL . str_repeat("  ", $depth)), $before);
        $parent->insertBefore($element, $before);
    }


    private function formatAttributes(array $attributes, array $skip): string
    {
        return $this->formatWritableAttributes($this->writableAttributes($attributes, $skip));
    }


    private function writableAttributes(array $attributes, array $skip): array
    {
        return array_filter(
            $attributes,
            fn (string $name): bool => $this->isWritable($name) && !in_array($name, $skip, true),
            ARRAY_FILTER_USE_KEY
        );
    }


    private function formatWritableAttributes(array $attributes): string
    {
        $output = "";
        foreach ($attributes as $name => $value) {
            $output .= $this->formatAttribute($name, $name === "xml:id" ? $this->unusedId($value) : $value);
        }

        return $output;
    }


    /**
     * Skips attributes whose prefix the output does not declare, for example a prefix declared on the paragraph.
     */
    private function isWritable(string $name): bool
    {
        if (!str_contains($name, ":")) {
            return true;
        }
        $prefix = explode(":", $name, 2)[0];

        return $prefix === "xml" || ($prefix !== "" && isset($this->namespaces[$prefix]));
    }


    private function formatAttribute(string $name, string $value): string
    {
        return " $name=\"" . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, "UTF-8") . "\"";
    }
}
