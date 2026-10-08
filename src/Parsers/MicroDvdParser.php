<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\MicroDvdReadOptions;
use SubtitleToolbox\StyleRuns;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class MicroDvdParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::MicroDvd->value;

    private const STYLE_TAGS = ["b", "i", "u", "s"];

    private const CUE_REGEX = '/^\{(\d+)\}\{(\d+)\}(.*)$/';


    protected static function formatOptionsClass(): string
    {
        return MicroDvdReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $rawLines = array_filter(
            array_map("trim", $this->lines($rawSubtitle)),
            fn (string $line): bool => $line !== ""
        );
        $blockIndexes = array_flip(array_keys($rawLines));

        // In lenient mode, the {1}{1}<fps> line can follow lines without frames.
        $firstLine = null;
        foreach ($rawLines as $lineIndex => $rawLine) {
            if (!$this->options->lenient || preg_match(self::CUE_REGEX, $rawLine)) {
                $firstLine = $lineIndex;
                break;
            }
        }
        $frameRate = $this->formatOptions()->frameRate;
        if ($firstLine !== null && preg_match('/^\{1\}\{1\}(\d+(?:\.\d+)?)$/', $rawLines[$firstLine], $matches)) {
            $frameRate ??= (float) $matches[1];
            unset($rawLines[$firstLine]);
        }

        if ($frameRate === null) {
            throw new ParsingException("The frame rate is unknown. Set MicroDvdReadOptions::\$frameRate or start the file with {1}{1}<fps>.");
        }

        try {
            $frames = new FrameRate($frameRate);
        } catch (InvalidArgumentException $exception) {
            throw new ParsingException($exception->getMessage(), null, $exception);
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["frameRate" => $frameRate]);
        foreach ($rawLines as $lineIndex => $rawLine) {
            if (!preg_match(self::CUE_REGEX, $rawLine, $matches)) {
                $lineNumber = $lineIndex + 1;
                $this->fail(new ParsingException("The line \"$rawLine\" is not a MicroDVD cue.", $lineNumber), $lineNumber, $blockIndexes[$lineIndex], [$rawLine]);
                continue;
            }

            $parsedCues[] = $this->parseCue(
                $frames->framesToSeconds((int) $matches[1]),
                $frames->framesToSeconds((int) $matches[2]),
                $matches[3]
            );
        }

        return $subtitle->addCues($parsedCues);
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

            $lineText = trim(substr($rawLine, strlen($prefix)));
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
            $lines[] = StyleRuns::toMarkup([[$lineText, ["color" => $color, ...array_fill_keys($tags, true)]]]);

            $lineCodes[$index] += ["color" => $color, "tags" => $tags];
        }

        return (new SubtitleCue($start, $end, $lines))->setFormatData(self::FORMAT_DATA_KEY, ["lines" => $lineCodes]);
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

        if ($letter === "c" && preg_match('/^\$([0-9A-Fa-f]{6})$/', $value, $bgr)) {
            $style["color"] = "#" . strtolower(Markup::bgrToRgb($bgr[1]));

            return $style;
        }

        return null;
    }
}
