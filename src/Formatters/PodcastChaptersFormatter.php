<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

class PodcastChaptersFormatter extends SubtitleFormatter
{
    private const VERSION = "1.2.0";


    public function format(Subtitle $subtitle, array $options = []): string
    {
        $stored = $subtitle->getFormatData(PodcastChaptersParser::FORMAT_DATA_KEY);
        $data   = ["version" => $stored["version"] ?? self::VERSION];
        foreach (["author" => Subtitle::METADATA_AUTHOR, "title" => Subtitle::METADATA_TITLE] as $field => $key) {
            if ($subtitle->getMetadata($key) !== null) {
                $data[$field] = $subtitle->getMetadata($key);
            }
        }
        $data += $stored;

        $cues             = array_values($subtitle->getCues());
        $data["chapters"] = [];
        foreach ($cues as $index => $cue) {
            $chapter     = ["startTime" => $this->number($cue->getStart())];
            $implicitEnd = isset($cues[$index + 1]) ? $cues[$index + 1]->getStart() : $cue->getStart();
            if ($cue->getEnd() !== $implicitEnd) {
                $chapter["endTime"] = $this->number($cue->getEnd());
            }
            $title = implode(" ", Markup::plainLines($cue->getLines()));
            if ($title !== "") {
                $chapter["title"] = $title;
            }
            $data["chapters"][] = $chapter + $cue->getFormatData(PodcastChaptersParser::FORMAT_DATA_KEY);
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->applyOutputOptions($json . StringHelpers::UNIX_LINE_ENDING, $options);
    }


    private function number(float $seconds): int|float
    {
        return floor($seconds) === $seconds ? (int) $seconds : $seconds;
    }
}
