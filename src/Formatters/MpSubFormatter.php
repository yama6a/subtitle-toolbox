<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Formatters\Options\MpSubWriteOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\MpSubParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class MpSubFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = MpSubWriteOptions::class;

    private const DEFAULT_TYPE = "VIDEO";
    private const DEFAULT_NOTE = "Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)";


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $fps         = $this->formatOptions($options)?->frameRate;
        $frameRate   = $fps === null ? null : new FrameRate($fps);
        $output      = $this->getHeader($subtitle, $frameRate);
        $previousEnd = 0;
        foreach ($subtitle->getCues() as $cue) {
            $output .= LineEnding::Lf->value;
            if ($frameRate === null) {
                $wait     = Timecode::totalMilliseconds($cue->getStart() - $previousEnd) / 1000;
                $duration = Timecode::totalMilliseconds($cue->getEnd() - $cue->getStart()) / 1000;
            } else {
                $wait     = $frameRate->secondsToFrames($cue->getStart()) - $frameRate->secondsToFrames($previousEnd);
                $duration = $frameRate->secondsToFrames($cue->getEnd()) - $frameRate->secondsToFrames($cue->getStart());
            }
            $output .= "$wait $duration" . LineEnding::Lf->value;
            $output .= Markup::plainText(implode(LineEnding::Lf->value, $cue->getLines()));
            $output .= LineEnding::Lf->value;

            $previousEnd = $cue->getEnd();
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
    }


    private function getHeader(Subtitle $subtitle, ?FrameRate $frameRate): string
    {
        $formatData = $subtitle->getFormatData(MpSubParser::FORMAT_DATA_KEY);
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
        $headers["FORMAT"] = $frameRate === null ? "TIME" : (string)(int)$frameRate->getFramesPerSecond();
        $headers["NOTE"]   = $formatData["NOTE"] ?? self::DEFAULT_NOTE;

        $header = "";
        foreach ($headers as $key => $value) {
            $header .= "$key=$value" . LineEnding::Lf->value;
        }

        return $header;
    }
}
