<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\SamiReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class SamiParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Sami->value;

    private const STYLE_TAGS = ["b" => "b", "i" => "i", "u" => "u", "s" => "s", "strike" => "s"];

    // The 16 colour names of HTML 4.01, section 6.5.
    private const COLOR_NAMES = [
        "black"  => "#000000", "silver" => "#c0c0c0", "gray"   => "#808080", "white"   => "#ffffff",
        "maroon" => "#800000", "red"    => "#ff0000", "purple" => "#800080", "fuchsia" => "#ff00ff",
        "green"  => "#008000", "lime"   => "#00ff00", "olive"  => "#808000", "yellow"  => "#ffff00",
        "navy"   => "#000080", "blue"   => "#0000ff", "teal"   => "#008080", "aqua"    => "#00ffff",
    ];

    private const NBSP = "\u{00A0}";


    protected static function formatOptionsClass(): string
    {
        return SamiReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($rawSubtitle));
        if (!preg_match('//u', $rawSubtitle)) {
            throw new ParsingException("The SAMI file is not valid UTF-8. Convert it to UTF-8 before parsing.");
        }

        $subtitle   = new Subtitle();
        $formatData = [];

        if (preg_match('/<TITLE\b[^>]*>(.*?)<\/TITLE\s*>/is', $rawSubtitle, $matches) && trim($matches[1]) !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_TITLE, html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, "UTF-8"));
        }
        if (preg_match('/<SAMIParam\b[^>]*>(.*?)<\/SAMIParam\s*>/is', $rawSubtitle, $matches)) {
            $formatData["samiParam"] = $matches[1];
        }

        $classes = [];
        if (preg_match('/<STYLE\b[^>]*>(.*?)<\/STYLE\s*>/is', $rawSubtitle, $matches)) {
            $formatData["style"] = $matches[1];
            $classes             = $this->readClasses($matches[1]);
        }

        $syncs = $this->readSyncs($rawSubtitle);
        $class = $this->chooseClass($classes, $syncs);
        if ($class !== null) {
            $formatData["class"] = $class;
            $language            = $classes[strtolower($class)]["lang"] ?? null;
            if ($language !== null && $language !== "") {
                $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
            }
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $formatData);

        $openCue = null;
        foreach ($syncs as $sync) {
            $paragraphs = $this->paragraphsFor($sync, $class);
            if ($paragraphs === null) {
                continue;
            }

            if ($openCue !== null) {
                $subtitle->addCue($openCue->setEnd($sync["start"]), false);
                $openCue = null;
            }

            $lines = array_merge(...array_column($paragraphs, "lines"));
            if ($lines !== []) {
                $openCue = (new SubtitleCue($sync["start"], $sync["start"], $lines))->setFormatData(self::FORMAT_DATA_KEY, [
                    "paragraphs" => array_map(fn (array $paragraph): array => [
                        "attributes" => $paragraph["attributes"],
                        "html"       => $paragraph["html"],
                    ], $paragraphs),
                    "lines"      => $lines,
                ]);
            }
        }

        if ($openCue !== null) {
            $subtitle->addCue($openCue->setEnd($openCue->getStart() + $this->options->lastCueDuration), false);
        }

        return $subtitle->reIndexCues();
    }


    /**
     * Reads the .CLASS rules of the STYLE block, keyed by the lowercase class name.
     *
     * @return array<string, array{name: string, lang: ?string}>
     */
    private function readClasses(string $style): array
    {
        $css = preg_replace('/\/\*.*?\*\/|<!--|-->/s', "", $style);
        preg_match_all('/\.([A-Za-z_][\w-]*)\s*\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $classes = [];
        foreach ($rules as [, $name, $body]) {
            $lang = null;
            foreach (explode(";", $body) as $declaration) {
                $parts = explode(":", $declaration, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === "lang") {
                    $lang = trim($parts[1], " \t\n\"'");
                }
            }
            $classes[strtolower($name)] ??= ["name" => $name, "lang" => $lang];
        }

        return $classes;
    }


    /**
     * @return list<array{start: float, paragraphs: list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>}>
     */
    private function readSyncs(string $rawSubtitle): array
    {
        $body = substr($rawSubtitle, self::bodyStart($rawSubtitle));
        if (preg_match('/<\/BODY\s*>/i', $body, $end, PREG_OFFSET_CAPTURE) === 1) {
            $body = substr($body, 0, $end[0][1]);
        }
        $body = preg_replace('/<\/SYNC\s*>/i', "", $body);

        $syncs = [];
        foreach (array_slice(preg_split('/<SYNC\b/i', $body, -1, PREG_SPLIT_OFFSET_CAPTURE), 1) as $index => [$chunk, $offset]) {
            try {
                [$start, $content] = $this->readSyncTag($chunk, $index);
            } catch (ParsingException $exception) {
                $lineNumber = $this->lineNumberInBody($rawSubtitle, $body, $offset);
                $lines      = array_map("trim", explode("\n", "<SYNC" . $chunk));
                $block      = array_values(array_filter($lines, fn (string $line): bool => $line !== ""));
                $this->fail($exception, $lineNumber, $index, $block);
                continue;
            }

            $syncs[] = ["start" => $start, "paragraphs" => $this->readParagraphs($content)];
        }

        usort($syncs, fn (array $sync1, array $sync2): int => $sync1["start"] <=> $sync2["start"]);

        return $syncs;
    }


    /**
     * @return array{float, string} the Start time in seconds and the content after the SYNC tag
     */
    private function readSyncTag(string $chunk, int $index): array
    {
        if (!preg_match('/^([^>]*)>(.*)$/s', $chunk, $matches) ||
            !preg_match('/\bStart\s*=\s*["\']?\s*(\d+)/i', $matches[1], $start)) {
            throw new ParsingException("SYNC tag " . ($index + 1) . " has no valid Start attribute.");
        }

        return [((int) $start[1]) / 1000, $matches[2]];
    }


    private function lineNumberInBody(string $rawSubtitle, string $body, int $offset): int
    {
        return 1 + substr_count($rawSubtitle, "\n", 0, self::bodyStart($rawSubtitle)) + substr_count(substr($body, 0, $offset), "\n");
    }


    /**
     * Returns the offset after the BODY start tag, or 0 without one. A pattern that starts with ^.*? would hit the
     * PCRE backtrack limit on files over about 1 MB.
     */
    private static function bodyStart(string $rawSubtitle): int
    {
        return preg_match('/<BODY\b[^>]*>/i', $rawSubtitle, $start, PREG_OFFSET_CAPTURE) === 1 ? $start[0][1] + strlen($start[0][0]) : 0;
    }


    /**
     * Reads the P elements of one SYNC block. Content outside a P becomes a paragraph without class.
     *
     * @return list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>
     */
    private function readParagraphs(string $html): array
    {
        $document             = new DOMDocument();
        $previousErrorSetting = libxml_use_internal_errors(true);
        try {
            // The meta tag makes libxml read the input as UTF-8 in place of ISO-8859-1.
            $document->loadHTML(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html,
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        $paragraphs = [];
        $loose      = [];
        $body       = $document->getElementsByTagName("body")->item(0);
        foreach ($body === null ? [] : $body->childNodes as $node) {
            if ($node instanceof DOMElement && $node->nodeName === "p") {
                $paragraphs[] = $this->readParagraph($document, $node, $node->hasAttribute("class") ? $node->getAttribute("class") : null);
            } else {
                $loose[] = $node;
            }
        }

        $looseText = implode("", array_map(fn (DOMNode $node): string => $node->textContent, $loose));
        if ($paragraphs === [] || trim(str_replace(self::NBSP, " ", $looseText)) !== "") {
            $wrapper = $document->createElement("p");
            foreach ($loose as $node) {
                $wrapper->appendChild($node);
            }
            array_unshift($paragraphs, $this->readParagraph($document, $wrapper, null));
        }

        return $paragraphs;
    }


    /**
     * @return array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}
     */
    private function readParagraph(DOMDocument $document, DOMElement $paragraph, ?string $class): array
    {
        $attributes = [];
        foreach ($paragraph->attributes as $attribute) {
            if ($attribute->nodeName !== "class") {
                $attributes[$attribute->nodeName] = $attribute->nodeValue;
            }
        }

        $html = "";
        foreach ($paragraph->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        $lines = [];
        foreach (explode("\n", $this->toMarkup($paragraph)) as $line) {
            $line = trim($line);
            if (trim($line, " " . self::NBSP) !== "") {
                $lines[] = $line;
            }
        }

        return ["class" => $class, "attributes" => $attributes, "html" => trim($html), "lines" => $lines];
    }


    private function toMarkup(DOMNode $node): string
    {
        $markup = "";
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $markup .= htmlspecialchars(preg_replace('/[ \t\n\r\f]+/', " ", $child->nodeValue), ENT_NOQUOTES, "UTF-8");
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }

            $inner = $this->toMarkup($child);
            $tag   = self::STYLE_TAGS[$child->nodeName] ?? null;
            $color = $child->nodeName === "font" ? $this->readColor($child->getAttribute("color")) : null;
            if ($child->nodeName === "br") {
                $markup .= "\n";
            } elseif ($tag !== null) {
                $markup .= "<$tag>$inner</$tag>";
            } elseif ($color !== null) {
                $markup .= "<font color=\"$color\">$inner</font>";
            } else {
                $markup .= $inner;
            }
        }

        return $markup;
    }


    private function readColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#?([0-9a-f]{6})$/', $value, $matches)) {
            return "#" . $matches[1];
        }

        return self::COLOR_NAMES[$value] ?? null;
    }


    /**
     * @param array<string, array{name: string, lang: ?string}> $classes
     */
    private function chooseClass(array $classes, array $syncs): ?string
    {
        $used = [];
        foreach ($syncs as $sync) {
            foreach ($sync["paragraphs"] as $paragraph) {
                if ($paragraph["class"] !== null) {
                    $used[strtolower($paragraph["class"])] ??= $paragraph["class"];
                }
            }
        }

        $language = $this->formatOptions()->language;
        if ($language !== null) {
            $key = strtolower($language);
            if (!isset($classes[$key]) && !isset($used[$key])) {
                throw new ParsingException("The SAMI file has no class $language.");
            }

            return $classes[$key]["name"] ?? $used[$key];
        }

        $first = reset($classes);

        return $first === false ? (reset($used) ?: null) : $first["name"];
    }


    /**
     * Returns the paragraphs of the SYNC block that the class shows, or null when the block does not touch the class.
     *
     * @return ?list<array{class: ?string, attributes: array<string, string>, html: string, lines: list<string>}>
     */
    private function paragraphsFor(array $sync, ?string $class): ?array
    {
        $paragraphs = array_values(array_filter(
            $sync["paragraphs"],
            fn (array $paragraph): bool => $paragraph["class"] === null || $class === null || strcasecmp($paragraph["class"], $class) === 0
        ));

        return $paragraphs === [] ? null : $paragraphs;
    }
}
