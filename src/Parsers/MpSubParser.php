<?php

namespace SubtitleToolbox\Parsers;

use InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MpSubParser extends SubtitleParser
{
    private const METADATA_HEADERS = [
        "TITLE"  => Subtitle::METADATA_TITLE,
        "AUTHOR" => Subtitle::METADATA_AUTHOR,
    ];


    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $lines       = explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);

        $subtitle   = new Subtitle();
        $formatData = [];
        $frameRate  = null;
        $position   = 0.0;
        $cue        = null;
        foreach ($lines as $lineIdx => $line) {
            $line       = trim($line);
            $lineNumber = $lineIdx + 1;

            if ($cue !== null) {
                if ($line !== "") {
                    $cue->addLine(htmlspecialchars($line, ENT_NOQUOTES, "UTF-8"));
                    continue;
                }

                $this->addCue($subtitle, $cue, $lineNumber - 1);
                $cue = null;
                continue;
            }

            if ($line === "" || str_starts_with($line, "#")) {
                continue;
            }

            if (preg_match("/^([A-Z]+)=(.*?)(\s+#.*)?$/", $line, $matches)) {
                $key   = $matches[1];
                $value = trim($matches[2]);

                if ($key === "FORMAT") {
                    $frameRate = $this->frameRateFromFormat($value, $lineNumber);
                } elseif (array_key_exists($key, self::METADATA_HEADERS)) {
                    $subtitle->setMetadata(self::METADATA_HEADERS[$key], $value === "" ? null : $value);
                } elseif ($value !== "") {
                    $formatData[$key] = $value;
                }
                continue;
            }

            if (!preg_match("/^(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)$/", $line, $matches)) {
                throw new ParsingException("Line $lineNumber is neither a header, a comment nor a timing line: $line");
            }

            $wait     = $this->toSeconds((float)$matches[1], $frameRate);
            $duration = $this->toSeconds((float)$matches[2], $frameRate);
            if ($duration < 0) {
                throw new ParsingException("The cue on line $lineNumber has a negative duration: $line");
            }

            $start    = $position + $wait;
            $position = $start + $duration;
            $cue      = new SubtitleCue($start, $position, []);
        }

        if ($cue !== null) {
            $this->addCue($subtitle, $cue, count($lines));
        }

        $subtitle->setFormatData("mpsub", $formatData);

        return $subtitle->reIndexCues();
    }


    private function addCue(Subtitle $subtitle, SubtitleCue $cue, int $lineNumber): void
    {
        if ($cue->getLines() === []) {
            throw new ParsingException("The cue that ends on line $lineNumber doesn't have any text lines!");
        }

        $subtitle->addCue($cue, false);
    }


    private function frameRateFromFormat(string $value, int $lineNumber): ?FrameRate
    {
        if ($value === "TIME") {
            return null;
        }

        // MPlayer and FFmpeg read only the leading integer, so "FORMAT=29.97" means 29 fps.
        if (!preg_match("/^\d+/", $value, $matches)) {
            throw new ParsingException("Line $lineNumber has an unknown FORMAT value: $value");
        }

        try {
            return new FrameRate((int)$matches[0]);
        } catch (InvalidArgumentException $e) {
            throw new ParsingException("Line $lineNumber has an invalid frame rate: $value");
        }
    }


    private function toSeconds(float $value, ?FrameRate $frameRate): float
    {
        if ($frameRate === null) {
            return $value;
        }

        // FrameRate::framesToSeconds() accepts only whole frames, but MPSub files may hold fractional frames.
        return $value / $frameRate->getFps();
    }
}
