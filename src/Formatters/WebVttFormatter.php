<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttFormatter extends SubtitleFormatter
{
    private const INLINE_TIMESTAMP_PATTERN = "/(<(?:\d{2,}:)?[0-5]\d:[0-5]\d\.\d{3}>)/";

    private const SPAN_TAGS = ["strong", "b", "u", "i", "v", "lang", "c", "ruby", "rt"];

    private const ALIGNMENT_ROWS    = [0 => "", 1 => "line:50%,center", 2 => "line:0"];
    private const ALIGNMENT_COLUMNS = [1 => "align:left", 2 => "", 3 => "align:right"];


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $fileData = $subtitle->getFormatData(WebVttParser::FORMAT);
        $header   = "WEBVTT";
        if (($fileData["header"] ?? "") !== "") {
            $header .= " " . $fileData["header"];
        }
        foreach ($fileData["headerLines"] ?? [] as $line) {
            $header .= StringHelpers::UNIX_LINE_ENDING . $line;
        }

        $blocks = [];
        foreach ($fileData["regions"] ?? [] as $region) {
            $blocks[] = $this->formatRegion($region);
        }
        foreach ($fileData["styles"] ?? [] as $style) {
            $blocks[] = "STYLE" . StringHelpers::UNIX_LINE_ENDING . $style;
        }

        $comments = $subtitle->getComments();
        foreach (array_values($subtitle->getCues()) as $cueIndex => $cue) {
            while ($comments !== [] && $comments[0]["beforeCueIndex"] <= $cueIndex) {
                $blocks[] = $this->formatComment(array_shift($comments)["text"]);
            }
            $blocks[] = $this->formatIdentifier($cue->getIdentifier(), $cueIndex) . StringHelpers::UNIX_LINE_ENDING
                        . $this->formatCue($cue, $options);
        }
        foreach ($comments as $comment) {
            $blocks[] = $this->formatComment($comment["text"]);
        }

        $output = $header . StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING;
        if ($blocks !== []) {
            $output .= implode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $blocks)
                       . StringHelpers::UNIX_LINE_ENDING;
        }

        return StringHelpers::addUtf8Bom($output);
    }


    private function formatRegion(array $region): string
    {
        $lines = ["REGION"];
        foreach ($region as $name => $value) {
            $lines[] = "$name:$value";
        }

        return implode(StringHelpers::UNIX_LINE_ENDING, $lines);
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

        $separator = str_contains($text, StringHelpers::UNIX_LINE_ENDING) ? StringHelpers::UNIX_LINE_ENDING : " ";

        return "NOTE" . $separator . $text;
    }


    private function formatIdentifier(?string $identifier, int $cueIndex): string
    {
        if ($identifier === null || trim($identifier) === "" || str_contains($identifier, "-->")
            || str_contains($identifier, StringHelpers::UNIX_LINE_ENDING)) {
            return (string) ($cueIndex + 1);
        }

        return $identifier;
    }


    private function formatCue(SubtitleCue $cue, array $options): string
    {
        $timeStamps = $this->formatTimeToString($cue->getStart()) . " --> " . $this->formatTimeToString($cue->getEnd());
        $settings   = $this->formatSettings($cue);
        if ($settings !== "") {
            $timeStamps .= " " . $settings;
        }

        $lines = implode(StringHelpers::UNIX_LINE_ENDING, $cue->getLines());
        $lines = in_array(parent::OPTION_STRIP_ALL_XML_TAGS, $options)
            ? Markup::stripAllTags($lines)
            : $this->keepVttTags($lines);

        return $timeStamps . StringHelpers::UNIX_LINE_ENDING . $lines;
    }


    private function formatSettings(SubtitleCue $cue): string
    {
        $settings = [];
        foreach ($cue->getFormatData(WebVttParser::FORMAT) as $name => $value) {
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
     * strip_tags() compares the whole name before the first space, so "c.yellow" must be allowed next to "c".
     *
     * @see https://www.w3.org/TR/webvtt1/#webvtt-cue-span-start-tag
     */
    private function spanTagNamesWithClasses(string $text): array
    {
        preg_match_all("/<\/?((?:" . implode("|", self::SPAN_TAGS) . ")\.[^\s>]*)/i", $text, $matches);

        $namesWithClasses = array_map(fn (string $name): string => rtrim($name, "/"), $matches[1]);

        return array_values(array_unique([...self::SPAN_TAGS, ...$namesWithClasses]));
    }


    private function formatTimeToString(float $timeInSeconds): string
    {
        $hour   = str_pad(floor($timeInSeconds / 3600), 2, "0", STR_PAD_LEFT);
        $minute = str_pad(floor($timeInSeconds / 60) % 60, 2, "0", STR_PAD_LEFT);
        $second = str_pad(floor($timeInSeconds) % 60, 2, "0", STR_PAD_LEFT);
        $millis = str_pad(round(($timeInSeconds - floor($timeInSeconds)) * 1000), 3, "0", STR_PAD_LEFT);

        return $hour . ":" . $minute . ":" . $second . "." . $millis;
    }
}
