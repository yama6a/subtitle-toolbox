<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class PodcastTranscriptParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::PodcastTranscript->value;

    private const SEGMENT_FIELDS = ["speaker", "startTime", "endTime", "body"];

    private const SENTENCE_END = '/[.?!\x{2026}]["\'\x{201D}\x{2019})\]]*$/u';


    protected static function formatOptionsClass(): string
    {
        return TranscriptReadOptions::class;
    }


    /**
     * Reads the Podcasting 2.0 JSON transcript, and joins single-word segments into cues by speaker and sentence end.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $data = $this->decodeJsonObject($rawSubtitle);
        if (!is_array($data["segments"] ?? null) || !array_is_list($data["segments"])) {
            throw new ParsingException("The JSON has no \"segments\" list.");
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($data, ["segments" => true]));

        foreach ($this->groups($this->readSegments($data["segments"])) as $group) {
            $speaker = $group[0]["speaker"];
            $words   = [];
            foreach ($group as $segment) {
                $timestamp = count($group) > 1 && $this->formatOptions()->wordTimestamps ? "<" . Markup::coreTimestamp($segment["start"]) . ">" : "";
                $words[]   = $timestamp . Markup::escapeText($segment["body"]);
            }
            $markup = implode(" ", $words);
            if ($speaker !== "") {
                $markup = Markup::voiceTag($speaker) . $markup;
            }

            $cue = new SubtitleCue($group[0]["start"], $group[count($group) - 1]["end"], $markup);
            if (count($group) === 1 && $group[0]["other"] !== []) {
                $cue->setFormatData(self::FORMAT_DATA_KEY, $group[0]["other"]);
            }
            $parsedCues[] = $cue;
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @return list<array{start: float, end: float, speaker: string, body: string, other: array}>
     */
    private function readSegments(array $segments): array
    {
        $result = [];
        foreach ($segments as $index => $segment) {
            try {
                $result[] = $this->readSegment($segment, "segments[$index]");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
            }
        }

        foreach ($result as $index => $segment) {
            if ($segment["end"] !== null) {
                continue;
            }

            $next = $index + 1;
            while ($next < count($result) && $result[$next]["start"] <= $segment["start"]) {
                $next++;
            }
            $result[$index]["end"] = $result[$next]["start"] ?? round($segment["start"] + $this->options->lastCueDuration, 3);
        }

        return array_values(array_filter($result, fn (array $segment): bool => $segment["body"] !== ""));
    }


    /**
     * @return array{start: float, end: ?float, speaker: string, body: string, other: array}
     */
    private function readSegment(mixed $segment, string $path): array
    {
        if (!is_array($segment) || ($segment !== [] && array_is_list($segment))) {
            throw new ParsingException("The field $path must be an object.");
        }

        foreach (["startTime" => "a number", "endTime" => "a number", "speaker" => "a string", "body" => "a string"] as $key => $type) {
            $value = $segment[$key] ?? null;
            $valid = $type === "a string" ? is_string($value) : self::isTime($value);
            if (!$valid && ($key === "startTime" || $value !== null)) {
                throw new ParsingException("The field $path.$key must be $type.");
            }
        }

        $end = $segment["endTime"] ?? null;

        return [
            "start"   => round($segment["startTime"], 3),
            "end"     => $end === null ? null : round($end, 3),
            "speaker" => trim($segment["speaker"] ?? ""),
            "body"    => trim(preg_replace('/[ \t\n\r]+/', " ", $segment["body"] ?? "") ?? ""),
            "other"   => array_diff_key($segment, array_flip(self::SEGMENT_FIELDS)),
        ];
    }


    /**
     * Joins runs of single-word segments of one speaker into a group that ends after a word with a sentence end.
     *
     * @return list<list<array>>
     */
    private function groups(array $segments): array
    {
        $groups = [];
        $open   = false;
        foreach ($segments as $segment) {
            $isWord = !$this->formatOptions()->keepSegments && !str_contains($segment["body"], " ");
            $last   = $open ? $groups[count($groups) - 1] : null;
            if ($isWord && $last !== null && $last[0]["speaker"] === $segment["speaker"]) {
                $groups[count($groups) - 1][] = $segment;
            } else {
                $groups[] = [$segment];
            }
            $open = $isWord && preg_match(self::SENTENCE_END, $segment["body"]) !== 1;
        }

        return $groups;
    }
}
