<?php

namespace SubtitleToolbox\Parsers;

use InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MicroDvdParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = "sub";

    private const STYLE_TAGS = ["b", "i", "u", "s"];

    protected ?float $frameRate;


    /**
     * Uses the given frame rate in place of the frame rate in a {1}{1}<fps> first line.
     */
    public function __construct(?float $frameRate = null)
    {
        $this->frameRate = $frameRate;
    }


    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawLines    = array_filter(
            array_map("trim", explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle)),
            fn (string $line): bool => $line !== ""
        );

        $frameRate = $this->frameRate;
        $firstLine = reset($rawLines);
        if ($firstLine !== false && preg_match('/^\{1\}\{1\}(\d+(?:\.\d+)?)$/', $firstLine, $matches)) {
            $frameRate ??= (float) $matches[1];
            unset($rawLines[array_key_first($rawLines)]);
        }

        if ($frameRate === null) {
            throw new ParsingException("The frame rate is unknown. Pass it to the constructor or start the file with {1}{1}<fps>.");
        }

        try {
            $frames = new FrameRate($frameRate);
        } catch (InvalidArgumentException $exception) {
            throw new ParsingException($exception->getMessage());
        }

        $subtitle = new Subtitle();
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["frameRate" => $frameRate]);
        foreach ($rawLines as $lineNumber => $rawLine) {
            if (!preg_match('/^\{(\d+)\}\{(\d+)\}(.*)$/', $rawLine, $matches)) {
                throw new ParsingException("Line " . ($lineNumber + 1) . " is not a MicroDVD cue: $rawLine");
            }

            $subtitle->addCue($this->parseCue(
                $frames->framesToSeconds((int) $matches[1]),
                $frames->framesToSeconds((int) $matches[2]),
                $matches[3]
            ));
        }

        return $subtitle;
    }


    private function parseCue(float $start, float $end, string $text): SubtitleCue
    {
        $cueStyle   = ["color" => null, "tags" => []];
        $lineStyles = [];
        $lineCodes  = [];
        $lineTexts  = [];
        foreach (explode("|", $text) as $rawLine) {
            preg_match('/^(?:\{[A-Za-z]:[^}]*\})*/', $rawLine, $matches);
            $prefix    = $matches[0];
            $lineStyle = ["color" => null, "tags" => []];
            $other     = "";
            preg_match_all('/\{([A-Za-z]):([^}]*)\}/', $prefix, $codes, PREG_SET_ORDER);
            foreach ($codes as [$code, $letter, $value]) {
                $isCueCode = ctype_upper($letter);
                $applied   = $this->applyCode(strtolower($letter), $value, $isCueCode ? $cueStyle : $lineStyle);
                if ($applied === null) {
                    $other .= $code;
                } elseif ($isCueCode) {
                    $cueStyle = $applied;
                } else {
                    $lineStyle = $applied;
                }
            }

            $lineText = trim($this->escapeText(substr($rawLine, strlen($prefix))));
            if ($lineText === "") {
                continue;
            }

            $lineStyles[] = $lineStyle;
            $lineCodes[]  = ["codes" => $prefix, "otherCodes" => $other];
            $lineTexts[]  = $lineText;
        }

        $lines = [];
        foreach ($lineTexts as $index => $lineText) {
            $color   = $lineStyles[$index]["color"] ?? $cueStyle["color"];
            $tags    = array_values(array_intersect(self::STYLE_TAGS, [...$cueStyle["tags"], ...$lineStyles[$index]["tags"]]));
            $markup  = ($color === null ? "" : "<font color=\"$color\">") . implode("", array_map(fn (string $tag): string => "<$tag>", $tags));
            $lines[] = $markup . $lineText . implode("", array_map(fn (string $tag): string => "</$tag>", array_reverse($tags))) .
                       ($color === null ? "" : "</font>");

            $lineCodes[$index] += ["color" => $color, "tags" => $tags];
        }

        return (new SubtitleCue($start, $end, $lines))->setFormatData(self::FORMAT_DATA_KEY, ["lines" => $lineCodes]);
    }


    // htmlspecialchars() returns an empty string for invalid UTF-8, so Latin-1 text would vanish.
    private function escapeText(string $text): string
    {
        return str_replace(["&", "<", ">"], ["&amp;", "&lt;", "&gt;"], $text);
    }


    /**
     * Returns the style with the {y:...} or {c:...} code applied, or null for codes outside the core markup.
     */
    private function applyCode(string $letter, string $value, array $style): ?array
    {
        if ($letter === "y" && preg_match('/^[biusBIUS, ]+$/', $value)) {
            $style["tags"] = [...$style["tags"], ...str_split(strtolower(str_replace([",", " "], "", $value)))];

            return $style;
        }

        if ($letter === "c" && preg_match('/^\$([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $value, $bgr)) {
            $style["color"] = strtolower("#" . $bgr[3] . $bgr[2] . $bgr[1]);

            return $style;
        }

        return null;
    }
}
