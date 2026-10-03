<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Options;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SamiFormatter extends SubtitleFormatter
{
    public const DEFAULT_CLASS = "SUBTTL";

    private const STYLE_TAGS = ["b", "i", "u", "s", "font"];

    private const NBSP = "\u{00A0}";


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $stripAll = (bool) (Options::flag($options, parent::OPTION_STRIP_ALL_XML_TAGS) ?? false);
        $data     = $subtitle->getFormatData(SamiParser::FORMAT_DATA_KEY);
        $language = $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE);
        $class    = isset($data["style"]) || isset($data["class"]) ? ($data["class"] ?? null) : $this->classFor($language);
        $style    = isset($data["style"]) ? $this->keepOnlyClass($data["style"], $class) : $this->defaultStyle($class, $language);
        $title    = $subtitle->getMetadata(Subtitle::METADATA_TITLE);
        $eol      = StringHelpers::UNIX_LINE_ENDING;

        $output = "<SAMI>$eol<HEAD>$eol";
        if ($title !== null) {
            $output .= "<TITLE>" . htmlspecialchars($title, ENT_NOQUOTES, "UTF-8") . "</TITLE>$eol";
        }
        if (isset($data["samiParam"])) {
            $output .= "<SAMIParam>{$data["samiParam"]}</SAMIParam>$eol";
        }
        $output .= "<STYLE TYPE=\"text/css\">$style</STYLE>$eol</HEAD>$eol<BODY>$eol";

        $cues = array_values($subtitle->getCues());
        foreach ($cues as $index => $cue) {
            $end    = $this->toMilliseconds($cue->getEnd());
            $output .= "<SYNC Start=" . $this->toMilliseconds($cue->getStart()) . ">" . $this->formatParagraphs($cue, $class, $stripAll) . $eol;

            $next = $cues[$index + 1] ?? null;
            if ($next === null || $this->toMilliseconds($next->getStart()) > $end) {
                $output .= "<SYNC Start=$end>" . $this->openParagraph($class, []) . "&nbsp;$eol";
            }
        }

        return $this->applyOutputOptions($output . "</BODY>$eol</SAMI>$eol", $options);
    }


    private function formatParagraphs(SubtitleCue $cue, ?string $class, bool $stripAll): string
    {
        $stored = $cue->getFormatData(SamiParser::FORMAT_DATA_KEY);
        if (!$stripAll && isset($stored["paragraphs"]) && ($stored["lines"] ?? null) === $cue->getLines()) {
            return implode("", array_map(
                fn (array $paragraph): string => $this->openParagraph($class, $paragraph["attributes"]) . $this->writeNbsp($paragraph["html"]),
                $stored["paragraphs"]
            ));
        }

        $lines = array_map(
            fn (string $line): string => $this->writeNbsp($stripAll ? Markup::stripAllTags($line) : Markup::keepTags($line, self::STYLE_TAGS)),
            $cue->getLines()
        );

        return $this->openParagraph($class, []) . ($lines === [] ? "&nbsp;" : implode("<br>", $lines));
    }


    /**
     * @param array<string, string> $attributes
     */
    private function openParagraph(?string $class, array $attributes): string
    {
        $tag = "<P" . ($class === null ? "" : " Class=$class");
        foreach ($attributes as $name => $value) {
            $tag .= " " . strtoupper($name) . "=" .
                    (preg_match('/^[\w.-]+$/', $value) ? $value : "\"" . htmlspecialchars($value, ENT_QUOTES, "UTF-8") . "\"");
        }

        return "$tag>";
    }


    private function writeNbsp(string $html): string
    {
        return str_replace(self::NBSP, "&nbsp;", $html);
    }


    /**
     * Removes the rules of the other language classes, because a player shows the first class of the STYLE block by default.
     */
    private function keepOnlyClass(string $style, ?string $class): string
    {
        if ($class === null) {
            return $style;
        }

        return preg_replace_callback(
            '/\n?[ \t]*\.([A-Za-z_][\w-]*)\s*\{[^}]*\}[ \t]*/',
            fn (array $matches): string => strcasecmp($matches[1], $class) === 0 ? $matches[0] : "",
            $style
        );
    }


    private function classFor(?string $language): string
    {
        $letters = preg_replace('/[^A-Za-z0-9]/', "", $language ?? "");

        return $letters === "" ? self::DEFAULT_CLASS : strtoupper($letters) . "CC";
    }


    private function defaultStyle(?string $class, ?string $language): string
    {
        $eol   = StringHelpers::UNIX_LINE_ENDING;
        $rules = $language === null ? "Name: Subtitles;" : "Name: $language; lang: $language;";

        return "<!--{$eol}P { font-family: Arial; text-align: center; }$eol.$class { $rules }$eol-->";
    }


    private function toMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
