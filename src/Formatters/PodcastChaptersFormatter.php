<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PodcastChaptersParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\WriteOptions;

final class PodcastChaptersFormatter extends SubtitleFormatter
{
    private const VERSION = "1.2.0";


    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
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

        $json = JsonOutput::encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->applyOutputOptions($json . LineEnding::Lf->value, $options);
    }


    private function number(float $seconds): int|float
    {
        return floor($seconds) === $seconds ? (int) $seconds : $seconds;
    }
}
