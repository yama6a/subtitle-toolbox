<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\MicroDvdWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\MicroDvdParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\WriteOptions;

final class MicroDvdFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = MicroDvdWriteOptions::class;

    private const STYLE_TAGS = ["b", "i", "u", "s"];


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $microDvd  = $this->formatOptions($options)
                     ?? throw new InvalidArgumentException("The MicroDVD formatter needs MicroDvdWriteOptions with a frame rate.");
        $frameRate = new FrameRate($microDvd->frameRate);
        $stripAll  = $options->stripTags;

        $output = "";
        if ($microDvd->writeFrameRateLine) {
            $output .= "{1}{1}" . $frameRate->getFps() . LineEnding::Lf->value;
        }

        foreach ($subtitle->getCues() as $cue) {
            $output .= "{" . $frameRate->secondsToFrames($cue->getStart()) . "}" .
                       "{" . $frameRate->secondsToFrames($cue->getEnd()) . "}" .
                       $this->formatText($cue, $stripAll) .
                       LineEnding::Lf->value;
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
                      preg_match('/^<font color=(["\']?)(#[0-9a-fA-F]{6})\1>(.*)<\/font>$/s', $line, $matches) &&
                      !preg_match('/<\/?font\b/', $matches[3])) {
                $color = strtolower($matches[2]);
                $line  = $matches[3];
            } else {
                break;
            }
        }

        return [
            "color" => $color,
            "tags"  => array_values(array_intersect(self::STYLE_TAGS, $tags)),
            "text"  => Markup::plainText($line),
        ];
    }
}
