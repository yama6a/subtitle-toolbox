<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MpSubFormatter extends SubtitleFormatter
{
    public const MPSUB_HEADER = "TITLE=" . StringHelpers::UNIX_LINE_ENDING .
                                "AUTHOR=" . StringHelpers::UNIX_LINE_ENDING .
                                "TYPE=VIDEO" . StringHelpers::UNIX_LINE_ENDING .
                                "FORMAT=TIME" . StringHelpers::UNIX_LINE_ENDING .
                                "NOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)" .
                                StringHelpers::UNIX_LINE_ENDING;

    /** Formatter option that sets a whole frame rate, for example 25, and makes the formatter write frames. */
    public const OPTION_FRAME_RATE = "OPTION_FRAME_RATE";

    private const DEFAULT_TYPE = "VIDEO";
    private const DEFAULT_NOTE = "Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)";


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $frameRate   = $this->frameRateFromOptions($options);
        $output      = $this->getHeader($subtitle, $frameRate);
        $previousEnd = 0;
        foreach ($subtitle->getCues() as $cue) {
            $output .= StringHelpers::UNIX_LINE_ENDING;
            $output .= $frameRate === null
                ? $this->getTimestamp($cue, $previousEnd)
                : $this->getFrameTimestamp($cue, $previousEnd, $frameRate);
            $output .= Markup::decodeEntities(Markup::stripAllTags(implode(StringHelpers::UNIX_LINE_ENDING, $cue->getLines())));
            $output .= StringHelpers::UNIX_LINE_ENDING;

            $previousEnd = $cue->getEnd();
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
    }


    private function frameRateFromOptions(array $options): ?FrameRate
    {
        if (!array_key_exists(self::OPTION_FRAME_RATE, $options)) {
            return null;
        }

        $fps = $options[self::OPTION_FRAME_RATE];
        // MPlayer and FFmpeg read FORMAT=<fps> as an integer.
        if (!is_int($fps) || $fps <= 0) {
            throw new InvalidArgumentException("The MPSub frame rate must be a positive integer!");
        }

        return new FrameRate($fps);
    }


    private function getHeader(Subtitle $subtitle, ?FrameRate $frameRate): string
    {
        $formatData = $subtitle->getFormatData("mpsub");
        $headers    = [
            "TITLE"  => $subtitle->getMetadata(Subtitle::METADATA_TITLE) ?? "",
            "AUTHOR" => $subtitle->getMetadata(Subtitle::METADATA_AUTHOR) ?? "",
            "TYPE"   => $formatData["TYPE"] ?? self::DEFAULT_TYPE,
        ];
        foreach ($formatData as $key => $value) {
            if (!in_array($key, ["TITLE", "AUTHOR", "TYPE", "FORMAT", "NOTE"], true)) {
                $headers[$key] = $value;
            }
        }
        $headers["FORMAT"] = $frameRate === null ? "TIME" : (string)(int)$frameRate->getFps();
        $headers["NOTE"]   = $formatData["NOTE"] ?? self::DEFAULT_NOTE;

        $header = "";
        foreach ($headers as $key => $value) {
            $header .= "$key=$value" . StringHelpers::UNIX_LINE_ENDING;
        }

        return $header;
    }


    private function getTimestamp(SubtitleCue $cue, float $previousEnd): string
    {
        $start    = round($cue->getStart() - $previousEnd, 3);
        $duration = round($cue->getEnd() - $cue->getStart(), 3);

        return $start . " " . $duration . StringHelpers::UNIX_LINE_ENDING;
    }


    private function getFrameTimestamp(SubtitleCue $cue, float $previousEnd, FrameRate $frameRate): string
    {
        $startFrame = $frameRate->secondsToFrames($cue->getStart());
        $wait       = $startFrame - $frameRate->secondsToFrames($previousEnd);
        $duration   = $frameRate->secondsToFrames($cue->getEnd()) - $startFrame;

        return $wait . " " . $duration . StringHelpers::UNIX_LINE_ENDING;
    }
}
