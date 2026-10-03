<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\PodcastTranscriptParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class PodcastTranscriptFormatter extends SubtitleFormatter
{
    /** Writes one segment per word timestamp, which apps use to highlight the spoken word. */
    public const OPTION_WORD_SEGMENTS = "wordSegments";
    public const OPTION_PRETTY_PRINT  = "prettyPrint";

    private const VERSION = "1.0.0";

    private const VOICE     = '/^<v(?:\.[^\s>]*)?(?:\s+([^>]*))?>$/';
    private const VOICE_END = '/^<\/v\s*>$/';


    /**
     * Writes the Podcasting 2.0 JSON transcript, one segment per cue and speaker.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $wordSegments = (bool)($options[self::OPTION_WORD_SEGMENTS] ?? false);
        $fileData     = $subtitle->getFormatData(PodcastTranscriptParser::FORMAT_DATA_KEY);
        $cues         = $subtitle->getCues();
        $pieces       = $this->pieces($subtitle, $wordSegments);
        $piecesPerCue = array_count_values(array_column($pieces, "cue"));

        $segments = [];
        foreach ($pieces as $piece) {
            $segment = $this->segment($piece);
            if (!$wordSegments && $piecesPerCue[$piece["cue"]] === 1) {
                $segment += $cues[$piece["cue"]]->getFormatData(PodcastTranscriptParser::FORMAT_DATA_KEY);
            }
            $segments[] = $segment;
        }

        $document = ["version" => $fileData["version"] ?? self::VERSION, "segments" => $segments] + $fileData;
        $flags    = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($options[self::OPTION_PRETTY_PRINT] ?? false) {
            $json = json_encode($document, $flags | JSON_PRETTY_PRINT) . StringHelpers::UNIX_LINE_ENDING;
        } else {
            $json = json_encode($document, $flags);
        }

        return $this->applyOutputOptions($json, $options);
    }


    /**
     * Returns the segments that format() writes, each with "startTime", "endTime", "body" and, when known, "speaker".
     *
     * @return list<array{speaker?: string, startTime: float, endTime: float, body: string}>
     */
    public function segments(Subtitle $subtitle, bool $wordSegments = false): array
    {
        return array_map($this->segment(...), $this->pieces($subtitle, $wordSegments));
    }


    private function segment(array $piece): array
    {
        return ($piece["speaker"] === null ? [] : ["speaker" => $piece["speaker"]]) +
               ["startTime" => $piece["start"], "endTime" => $piece["end"], "body" => $piece["body"]];
    }


    /**
     * Splits each cue at a speaker change, and at each word timestamp when $wordSegments is true.
     *
     * @return list<array{cue: int, speaker: ?string, start: float, end: float, body: string}>
     */
    private function pieces(Subtitle $subtitle, bool $wordSegments): array
    {
        $pieces = [];
        foreach ($subtitle->getCues() as $index => $cue) {
            $cuePieces = [];
            $speaker   = null;
            $start     = $cue->getStart();
            $text      = "";
            $tokens    = preg_split('/(<[^<>]*>)/', implode(" ", $cue->getLines()), -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($tokens as $position => $token) {
                if ($position % 2 === 0) {
                    $text .= $token;
                    continue;
                }

                $isVoice     = preg_match(self::VOICE, $token, $voice) === 1;
                $isTimestamp = $wordSegments && preg_match(Markup::WORD_TIMESTAMP_REGEX, $token) === 1;
                if (!$isVoice && !$isTimestamp && preg_match(self::VOICE_END, $token) !== 1) {
                    continue;
                }

                $name = $isVoice ? trim(Markup::decodeEntities($voice[1] ?? "")) : null;
                $name = $isTimestamp ? $speaker : ($name === "" ? null : $name);
                if (!$isTimestamp && $name === $speaker) {
                    continue;
                }

                $cuePieces = $this->addPiece($cuePieces, $index, $speaker, $start, $text);
                $speaker   = $name;
                $start     = $isTimestamp ? $this->timestampInCue($token, $cue) : $start;
                $text      = "";
            }
            $cuePieces = $this->addPiece($cuePieces, $index, $speaker, $start, $text);

            foreach (array_keys($cuePieces) as $offset) {
                $cuePieces[$offset]["end"] = $wordSegments ? $cuePieces[$offset + 1]["start"] ?? $cue->getEnd() : $cue->getEnd();
            }
            array_push($pieces, ...$cuePieces);
        }

        return $pieces;
    }


    private function addPiece(array $pieces, int $cue, ?string $speaker, float $start, string $text): array
    {
        $body = trim(preg_replace('/[ \t\n\r]+/', " ", Markup::decodeEntities($text)) ?? "");
        if ($body !== "") {
            $pieces[] = ["cue" => $cue, "speaker" => $speaker, "start" => $start, "end" => $start, "body" => $body];
        }

        return $pieces;
    }


    private function timestampInCue(string $token, SubtitleCue $cue): float
    {
        [$hours, $minutes, $seconds] = explode(":", trim($token, "<>"));
        $time = (int)$hours * 3600 + (int)$minutes * 60 + (float)$seconds;

        return round(min(max($time, $cue->getStart()), $cue->getEnd()), 3);
    }
}
