<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class WebVttFormatter extends SubtitleFormatter
{
    private const INLINE_TIMESTAMP_PATTERN = "/(<(?:\d{2,}:)?[0-5]\d:[0-5]\d\.\d{3}>)/";

    private const SPAN_TAGS = ["strong", "b", "u", "i", "v", "lang", "c", "ruby", "rt"];

    private const ALIGNMENT_ROWS    = [0 => "", 1 => "line:50%,center", 2 => "line:0"];
    private const ALIGNMENT_COLUMNS = [1 => "align:left", 2 => "", 3 => "align:right"];


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $fileData = $subtitle->findFormatData(WebVttParser::FORMAT_DATA_KEY);
        $header   = "WEBVTT";
        if (($fileData["header"] ?? "") !== "") {
            $header .= " " . $fileData["header"];
        }
        foreach ($fileData["headerLines"] ?? [] as $line) {
            $header .= LineEnding::Lf->value . $line;
        }

        $blocks = [];
        foreach ($fileData["regions"] ?? [] as $region) {
            $blocks[] = $this->formatRegion($region);
        }
        foreach ($fileData["styles"] ?? [] as $style) {
            $blocks[] = "STYLE" . LineEnding::Lf->value . $style;
        }

        $comments = $subtitle->getComments();
        foreach (array_values($subtitle->getCues()) as $cueIndex => $cue) {
            while ($comments !== [] && $comments[0]->beforeCueIndex <= $cueIndex) {
                $blocks[] = $this->formatComment(array_shift($comments)->text);
            }
            $blocks[] = $this->formatIdentifiedCue($cue, $cueIndex, $options);
        }
        foreach ($comments as $comment) {
            $blocks[] = $this->formatComment($comment->text);
        }

        $output = $header . LineEnding::Lf->value . LineEnding::Lf->value;
        if ($blocks !== []) {
            $output .= implode(LineEnding::Lf->value . LineEnding::Lf->value, $blocks)
                       . LineEnding::Lf->value;
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
    }


    /**
     * Returns the cue block format() writes for the cue at $cueIndex, in the line ending of $options, without a BOM.
     *
     * @internal WebVttStreamWriter calls it.
     */
    public function formatCueBlock(SubtitleCue $cue, int $cueIndex, WriteOptions $options = new WriteOptions()): string
    {
        $block = $this->formatIdentifiedCue($cue, $cueIndex, $options);

        return $this->applyOutputOptions($block, new WriteOptions($options->lineEnding, format: $options->format));
    }


    private function formatIdentifiedCue(SubtitleCue $cue, int $cueIndex, WriteOptions $options): string
    {
        return $this->formatIdentifier($cue->getIdentifier(), $cueIndex) . LineEnding::Lf->value
               . $this->formatCue($cue, $options);
    }


    private function formatRegion(array $region): string
    {
        $lines = ["REGION"];
        foreach ($region as $name => $value) {
            $lines[] = "$name:$value";
        }

        return implode(LineEnding::Lf->value, $lines);
    }


    /**
     * @see https://www.w3.org/TR/webvtt1/#webvtt-comment-block
     */
    private function formatComment(string $text): string
    {
        $text = StringHelpers::cleanString(str_replace("-->", "->", $text));
        if ($text === "") {
            return "NOTE";
        }

        $separator = str_contains($text, LineEnding::Lf->value) ? LineEnding::Lf->value : " ";

        return "NOTE" . $separator . $text;
    }


    private function formatIdentifier(?string $identifier, int $cueIndex): string
    {
        if ($identifier === null || trim($identifier) === "" || str_contains($identifier, "-->")
            || str_contains($identifier, LineEnding::Lf->value)) {
            return (string) ($cueIndex + 1);
        }

        return $identifier;
    }


    private function formatCue(SubtitleCue $cue, WriteOptions $options): string
    {
        $timeStamps = sprintf("%02d:%02d:%02d.%03d --> %02d:%02d:%02d.%03d", ...Timecode::milliseconds($cue->getStart()), ...Timecode::milliseconds($cue->getEnd()));
        $settings   = $this->formatSettings($cue);
        if ($settings !== "") {
            $timeStamps .= " " . $settings;
        }

        $lines = implode(LineEnding::Lf->value, $cue->getLines());
        $lines = $options->stripTags
            ? Markup::stripAllTags($lines)
            : $this->keepVttTags($lines);

        return $timeStamps . LineEnding::Lf->value . $lines;
    }


    private function formatSettings(SubtitleCue $cue): string
    {
        $settings = [];
        foreach ($cue->findFormatData(WebVttParser::FORMAT_DATA_KEY) as $name => $value) {
            if (in_array($name, WebVttParser::CUE_SETTINGS, true)) {
                $settings[] = "$name:$value";
            }
        }
        if ($settings !== [] || $cue->getAlignment() === null) {
            return implode(" ", $settings);
        }

        $alignment = $cue->getAlignment() - 1;
        $settings  = [self::ALIGNMENT_ROWS[intdiv($alignment, 3)], self::ALIGNMENT_COLUMNS[$alignment % 3 + 1]];

        return implode(" ", array_filter($settings, fn (string $setting): bool => $setting !== ""));
    }


    private function keepVttTags(string $text): string
    {
        $parts = preg_split(self::INLINE_TIMESTAMP_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $idx => $part) {
            if ($idx % 2 === 0) {
                $parts[$idx] = Markup::keepTags($part, $this->spanTagNamesWithClasses($part));
            }
        }

        return implode("", $parts);
    }


    /**
     * Markup::keepTags() compares the whole name before the first space, so "c.yellow" must be allowed next to "c".
     *
     * @see https://www.w3.org/TR/webvtt1/#webvtt-cue-span-start-tag
     */
    private function spanTagNamesWithClasses(string $text): array
    {
        preg_match_all("/<\/?((?:" . implode("|", self::SPAN_TAGS) . ")\.[^\s>]*)/i", $text, $matches);

        $namesWithClasses = array_map(fn (string $name): string => rtrim($name, "/"), $matches[1]);

        return array_values(array_unique([...self::SPAN_TAGS, ...$namesWithClasses]));
    }
}
