<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class PodcastChaptersFormatter extends SubtitleFormatter
{
    private const VERSION = "1.2.0";


    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $stored = $subtitle->findFormatData(PodcastChaptersParser::FORMAT_DATA_KEY);
        $data   = ["version" => $stored["version"] ?? self::VERSION];
        foreach (["author" => Subtitle::METADATA_AUTHOR, "title" => Subtitle::METADATA_TITLE] as $field => $key) {
            if ($subtitle->findMetadata($key) !== null) {
                $data[$field] = $subtitle->findMetadata($key);
            }
        }
        $data += $stored;

        $cues             = array_values($subtitle->getCues());
        $data["chapters"] = [];
        foreach ($cues as $index => $cue) {
            $chapter     = ["startTime" => $this->number($cue->getStart())];
            $implicitEnd = $this->number(isset($cues[$index + 1]) ? $cues[$index + 1]->getStart() : $cue->getStart());
            if ($this->number($cue->getEnd()) !== $implicitEnd) {
                $chapter["endTime"] = $this->number($cue->getEnd());
            }
            $title = implode(" ", Markup::plainLines($cue->getLines()));
            if ($title !== "") {
                $chapter["title"] = $title;
            }
            $data["chapters"][] = $chapter + $cue->findFormatData(PodcastChaptersParser::FORMAT_DATA_KEY);
        }

        $json = JsonOutput::document($data, true, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->applyOutputOptions($json, $options);
    }


    private function number(float $seconds): int|float
    {
        $seconds = max(0.0, $seconds);

        return floor($seconds) === $seconds ? (int) $seconds : $seconds;
    }
}
