<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\MpSubOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

class MpSubFormatter extends SubtitleFormatter
{
    public const MPSUB_HEADER = "TITLE=" . StringHelpers::UNIX_LINE_ENDING .
                                "AUTHOR=" . StringHelpers::UNIX_LINE_ENDING .
                                "TYPE=VIDEO" . StringHelpers::UNIX_LINE_ENDING .
                                "FORMAT=TIME" . StringHelpers::UNIX_LINE_ENDING .
                                "NOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)" .
                                StringHelpers::UNIX_LINE_ENDING;

    protected const FORMAT_OPTIONS = MpSubOptions::class;

    private const DEFAULT_TYPE = "VIDEO";
    private const DEFAULT_NOTE = "Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)";


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $fps         = $this->formatOptions($options)?->frameRate;
        $frameRate   = $fps === null ? null : new FrameRate($fps);
        $output      = $this->getHeader($subtitle, $frameRate);
        $previousEnd = 0;
        foreach ($subtitle->getCues() as $cue) {
            $output .= StringHelpers::UNIX_LINE_ENDING;
            if ($frameRate === null) {
                $wait     = Timecode::totalMilliseconds($cue->getStart() - $previousEnd) / 1000;
                $duration = Timecode::totalMilliseconds($cue->getEnd() - $cue->getStart()) / 1000;
            } else {
                $wait     = $frameRate->secondsToFrames($cue->getStart()) - $frameRate->secondsToFrames($previousEnd);
                $duration = $frameRate->secondsToFrames($cue->getEnd()) - $frameRate->secondsToFrames($cue->getStart());
            }
            $output .= "$wait $duration" . StringHelpers::UNIX_LINE_ENDING;
            $output .= Markup::plainText(implode(StringHelpers::UNIX_LINE_ENDING, $cue->getLines()));
            $output .= StringHelpers::UNIX_LINE_ENDING;

            $previousEnd = $cue->getEnd();
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
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
}
