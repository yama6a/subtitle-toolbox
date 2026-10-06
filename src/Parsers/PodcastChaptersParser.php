<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

// Spec: https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/examples/chapters/jsonChapters.md
final class PodcastChaptersParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::PodcastChapters->value;


    protected static function formatOptionsClass(): string
    {
        return ChapterReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $data = $this->decodeJsonObject($rawSubtitle);
        if (!is_array($data["chapters"] ?? null) || !array_is_list($data["chapters"])) {
            throw new ParsingException("The JSON has no \"chapters\" list.");
        }

        $chapters = [];
        foreach ($data["chapters"] as $index => $chapter) {
            $start = is_array($chapter) ? $chapter["startTime"] ?? null : null;
            if (!self::isTime($start)) {
                throw new ParsingException("The field chapters[$index].startTime must be a number.");
            }
            $end   = $chapter["endTime"] ?? null;
            if ($end !== null && !self::isTime($end)) {
                throw new ParsingException("The field chapters[$index].endTime must be a number.");
            }
            $title = $chapter["title"] ?? null;

            $cue = new SubtitleCue($start, $start, is_string($title) ? Markup::escapeText($title) : "");
            $cue->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($chapter, array_flip(["startTime", "endTime", "title"])));
            $chapters[] = [$cue, $end === null ? null : (float) $end];
        }
        usort($chapters, fn (array $a, array $b): int => $a[0]->getStart() <=> $b[0]->getStart());

        $subtitle = new Subtitle();
        foreach ($chapters as $index => [$cue, $end]) {
            $next = $chapters[$index + 1][0] ?? null;
            $cue->setEnd($end ?? $next?->getStart() ?? max($cue->getStart(), $this->formatOptions()->mediaDuration ?? 0));
        }
        $subtitle->addCues(array_column($chapters, 0));

        foreach (["title" => Subtitle::METADATA_TITLE, "author" => Subtitle::METADATA_AUTHOR] as $field => $key) {
            if (is_string($data[$field] ?? null)) {
                $subtitle->setMetadata($key, $data[$field]);
            }
        }

        return $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($data, array_flip(["chapters", "title", "author"])));
    }
}
