<?php

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MicroDvdFormatter extends SubtitleFormatter
{
    public const OPTION_FRAME_RATE            = "OPTION_FRAME_RATE";
    public const OPTION_WRITE_FRAME_RATE_LINE = "OPTION_WRITE_FRAME_RATE_LINE";

    private const STYLE_TAGS = ["b", "i", "u", "s"];


    public function format(Subtitle $subtitle, array $options = []): string
    {
        if (!isset($options[self::OPTION_FRAME_RATE])) {
            throw new InvalidArgumentException("The MicroDVD formatter needs the option " . self::OPTION_FRAME_RATE . ".");
        }

        $frameRate = new FrameRate((float) $options[self::OPTION_FRAME_RATE]);
        $stripAll  = in_array(parent::OPTION_STRIP_ALL_XML_TAGS, $options, true);

        $output = "";
        if (!empty($options[self::OPTION_WRITE_FRAME_RATE_LINE])) {
            $output .= "{1}{1}" . $frameRate->getFps() . StringHelpers::UNIX_LINE_ENDING;
        }

        foreach ($subtitle->getCues() as $cue) {
            $output .= "{" . $frameRate->secondsToFrames($cue->getStart()) . "}" .
                       "{" . $frameRate->secondsToFrames($cue->getEnd()) . "}" .
                       $this->formatText($cue, $stripAll) .
                       StringHelpers::UNIX_LINE_ENDING;
        }

        return $this->applyOutputOptions($output, $options);
    }


    private function formatText(SubtitleCue $cue, bool $stripAll): string
    {
        $storedLines = $cue->getFormatData(MicroDvdParser::FORMAT_DATA_KEY)["lines"] ?? [];
        $lines       = array_map(fn (string $line): array => $this->readLine($line), $cue->getLines());

        $keepStoredCodes = !$stripAll && count($storedLines) === count($lines);
        foreach ($lines as $index => $line) {
            $keepStoredCodes = $keepStoredCodes &&
                               $line["color"] === $storedLines[$index]["color"] &&
                               $line["tags"] === $storedLines[$index]["tags"];
        }

        $texts = [];
        foreach ($lines as $index => $line) {
            if ($keepStoredCodes) {
                $texts[] = $storedLines[$index]["codes"] . $line["text"];
                continue;
            }

            $codes = "";
            if (!$stripAll) {
                $codes .= $line["color"] === null ? "" : "{c:$" . strtoupper(substr($line["color"], 5, 2) . substr($line["color"], 3, 2) . substr($line["color"], 1, 2)) . "}";
                $codes .= implode("", array_map(fn (string $tag): string => "{y:$tag}", $line["tags"]));
            }
            $texts[] = $codes . ($storedLines[$index]["otherCodes"] ?? "") . $line["text"];
        }

        return implode("|", $texts);
    }


    /**
     * Splits a cue line into the core markup tags that wrap the whole line and the plain text inside them.
     *
     * @return array{color: ?string, tags: list<string>, text: string}
     */
    private function readLine(string $line): array
    {
        $color = null;
        $tags  = [];
        while (true) {
            if (preg_match('/^<([bius])>(.*)<\/\1>$/s', $line, $matches) && !preg_match("/<\/?$matches[1]>/", $matches[2])) {
                $tags[] = $matches[1];
                $line   = $matches[2];
            } elseif ($color === null &&
                      preg_match('/^<font color="(#[0-9a-fA-F]{6})">(.*)<\/font>$/s', $line, $matches) &&
                      !preg_match('/<\/?font\b/', $matches[2])) {
                $color = strtolower($matches[1]);
                $line  = $matches[2];
            } else {
                break;
            }
        }

        return [
            "color" => $color,
            "tags"  => array_values(array_intersect(self::STYLE_TAGS, $tags)),
            "text"  => Markup::decodeEntities(Markup::stripAllTags($line)),
        ];
    }
}
