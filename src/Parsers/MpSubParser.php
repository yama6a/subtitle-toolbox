<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class MpSubParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::MpSub->value;

    private const METADATA_HEADERS = [
        "TITLE"  => Subtitle::METADATA_TITLE,
        "AUTHOR" => Subtitle::METADATA_AUTHOR,
    ];


    private ?FrameRate $frameRate = null;

    private bool $hasFormat = false;

    /** @var array<string, string> */
    private array $formatData = [];


    protected function read(string $content): Subtitle
    {
        $lines            = $this->lines($content);
        $this->frameRate  = null;
        $this->hasFormat  = false;
        $this->formatData = [];
        $subtitle         = new Subtitle();
        $parsedCues       = [];
        $position         = 0.0;
        $cue              = null;
        $cueLine          = 0;
        $cueIndex         = 0;
        $skipping         = false;
        foreach ($lines as $lineIndex => $line) {
            $line       = trim($line);
            $lineNumber = $lineIndex + 1;
            if ($cue !== null && $line !== "") {
                $cue->addLine(Markup::escapeText($line));
                continue;
            }
            if ($cue !== null) {
                $this->addCue($parsedCues, $cue, $lineNumber - 1, $cueLine, $cueIndex - 1, $lines);
                $cue = null;
                continue;
            }
            if ($skipping) {
                $skipping = $line !== "";
                continue;
            }
            if ($line === "" || str_starts_with($line, "#")) {
                continue;
            }
            if (preg_match("/^([A-Z]+)=(.*?)(\s+#.*)?$/", $line, $matches)) {
                $this->readHeader($matches[1], trim($matches[2]), $line, $lineNumber, $cueIndex, $subtitle);
                continue;
            }

            $cue      = $this->startCue($line, $lineNumber, $cueIndex++, $position);
            $skipping = $cue === null;
            $cueLine  = $lineNumber;
        }

        if ($cue !== null) {
            $this->addCue($parsedCues, $cue, count($lines), $cueLine, $cueIndex - 1, $lines);
        }

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $this->formatData);

        return $subtitle->addCues($parsedCues);
    }


    private function readHeader(string $key, string $value, string $line, int $lineNumber, int $cueIndex, Subtitle $subtitle): void
    {
        if ($key === "FORMAT") {
            $this->hasFormat = true;
            try {
                $this->frameRate = $this->frameRateFromFormat($value, $lineNumber);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $cueIndex, [$line]);
            }
        } elseif (array_key_exists($key, self::METADATA_HEADERS)) {
            $subtitle->setMetadata(self::METADATA_HEADERS[$key], $value === "" ? null : $value);
        } elseif ($value !== "") {
            $this->formatData[$key] = $value;
        }
    }


    /**
     * Returns the cue of a timing line without text, or null when the line fails in lenient mode.
     */
    private function startCue(string $line, int $lineNumber, int $cueIndex, float &$position): ?SubtitleCue
    {
        if ($this->options->lenient && !$this->hasFormat) {
            $this->warn(
                "The file has no FORMAT line before line $lineNumber. The parser read the times as seconds.",
                $lineNumber,
                $cueIndex,
                [$line],
                ParseWarningAction::Repaired
            );
            $this->hasFormat = true;
        }

        try {
            return $this->readTimingLine($line, $lineNumber, $this->frameRate, $position);
        } catch (ParsingException $exception) {
            $this->fail($exception, $lineNumber, $cueIndex, [$line]);

            return null;
        }
    }


    /**
     * Returns the cue of a timing line without text, and moves $position to its end.
     */
    private function readTimingLine(string $line, int $lineNumber, ?FrameRate $frameRate, float &$position): SubtitleCue
    {
        if (!preg_match("/^(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)$/", $line, $matches)) {
            throw new ParsingException("The line \"$line\" is not a header, a comment or a timing line.", $lineNumber);
        }

        $wait     = $this->toSeconds((float)$matches[1], $frameRate);
        $duration = $this->toSeconds((float)$matches[2], $frameRate);
        if ($duration < 0) {
            throw new ParsingException("The timing line \"$line\" has a negative duration.", $lineNumber);
        }

        $start    = $position + $wait;
        $position = $start + $duration;

        return new SubtitleCue($start, $position, []);
    }


    /**
     * @param list<string> $lines
     */
    private function addCue(array &$cues, SubtitleCue $cue, int $lineNumber, int $cueLine, int $cueIndex, array $lines): void
    {
        if ($cue->getLines() === []) {
            $this->fail(new ParsingException("The cue has no text lines.", $lineNumber), $cueLine, $cueIndex, [trim($lines[$cueLine - 1])]);

            return;
        }

        $cues[] = $cue;
    }


    private function frameRateFromFormat(string $value, int $lineNumber): ?FrameRate
    {
        if ($value === "TIME") {
            return null;
        }

        // MPlayer and FFmpeg read only the leading integer, so "FORMAT=29.97" means 29 fps.
        if (!preg_match("/^\d+/", $value, $matches)) {
            throw new ParsingException("The FORMAT value \"$value\" is not known.", $lineNumber);
        }

        try {
            return new FrameRate((int)$matches[0]);
        } catch (InvalidArgumentException $e) {
            throw new ParsingException("The frame rate \"$value\" is not valid.", $lineNumber, $e);
        }
    }


    private function toSeconds(float $value, ?FrameRate $frameRate): float
    {
        if ($frameRate === null) {
            return $value;
        }

        // FrameRate::framesToSeconds() accepts only whole frames, but MPSub files may hold fractional frames.
        return $value / $frameRate->getFramesPerSecond();
    }
}
