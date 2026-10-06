<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

final class GoogleSpeechParser extends SubtitleParser
{
    use WordGrouping;

    public const FORMAT_DATA_KEY = Format::GoogleSpeech->value;


    /**
     * Reads the JSON response of Google Cloud Speech-to-Text V1 and V2, one cue per result. With speaker
     * diarization, it groups the words of the last result into cues.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $data           = $this->decodeObject($rawSubtitle);
        $fileData       = [];
        if (is_array($data["response"] ?? null)) {
            $fileData = array_diff_key($data, ["response" => true]);
            $data     = $data["response"];
        }
        if (!is_array($data["results"] ?? null) || !array_is_list($data["results"])) {
            throw new ParsingException("The JSON has no \"results\" list.");
        }

        $results   = $data["results"];
        $lastIndex = count($results) - 1;
        $lastWords = self::listOrEmpty($results[$lastIndex]["alternatives"][0]["words"] ?? null);
        // With diarization, each result repeats the words of the results before it, so the last result holds all words.
        $diarized = array_filter($lastWords, fn (mixed $word): bool => isset($word["speakerLabel"]) || !empty($word["speakerTag"])) !== [];
        $cues     = $diarized
            ? $this->cuesFromWords($this->readWords($lastWords, "results[$lastIndex].alternatives[0].words"), "words")
            : $this->readResults($results);

        $subtitle = new Subtitle();
        foreach ($results as $result) {
            if (is_string($result["languageCode"] ?? null) && $result["languageCode"] !== "") {
                $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $result["languageCode"]);
                break;
            }
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData + array_diff_key($data, ["results" => true]));

        return $subtitle->addCues($cues);
    }


    private function readResults(array $results): array
    {
        $cues        = [];
        $previousEnd = 0.0;
        foreach ($results as $index => $result) {
            $path        = "results[$index]";
            $alternative = is_array($result) && is_array($result["alternatives"][0] ?? null) ? $result["alternatives"][0] : null;
            $resultEnd   = is_array($result) ? self::duration($result["resultEndTime"] ?? $result["resultEndOffset"] ?? null) : null;
            try {
                if (is_array($result) && isset($result["alternatives"]) && $alternative === null && $result["alternatives"] !== []) {
                    throw new ParsingException("The field $path.alternatives must be a list of objects.");
                }
                $words = $this->readWords(self::listOrEmpty($alternative["words"] ?? null), "$path.alternatives[0].words");
                $end   = $words === [] ? $this->seconds($resultEnd, "$path.resultEndTime") : $words[count($words) - 1]["end"];
                $start = $words === [] ? $previousEnd : $words[0]["start"];
                $text  = is_array($alternative) ? $this->text($alternative, "transcript", "$path.alternatives[0]") : "";
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($result)]);
                continue;
            }
            $previousEnd = is_int($resultEnd) || (is_float($resultEnd) && is_finite($resultEnd)) ? round($resultEnd, 3) : $end;

            $formatData = array_diff_key($result, ["alternatives" => true]) + array_diff_key($alternative ?? [], ["transcript" => true]);
            $cue        = $this->cue($start, $end, $text, $words, null, $formatData);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }


    private function readWords(array $words, string $path): array
    {
        $result = [];
        foreach ($words as $index => $word) {
            try {
                $text  = $this->text($word, "word", "{$path}[$index]");
                $start = $this->seconds(self::duration($word["startTime"] ?? $word["startOffset"] ?? null), "{$path}[$index].startTime");
                $end   = $this->seconds(self::duration($word["endTime"] ?? $word["endOffset"] ?? null), "{$path}[$index].endTime");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($word)]);
                continue;
            }

            $speaker  = $word["speakerLabel"] ?? (($word["speakerTag"] ?? 0) === 0 ? null : $word["speakerTag"]);
            $result[] = ["text" => trim($text), "start" => $start, "end" => $end, "speaker" => self::speaker($speaker), "data" => [$word]];
        }

        return $result;
    }


    /**
     * Returns the seconds of a protobuf Duration in JSON, such as "1.300s", or of an object with seconds and nanos.
     */
    private static function duration(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)s$/', $value, $match) === 1) {
            return (float) $match[1];
        }
        if (is_array($value) && (isset($value["seconds"]) || isset($value["nanos"]))
            && is_numeric($value["seconds"] ?? 0) && is_numeric($value["nanos"] ?? 0)) {
            return (float) ($value["seconds"] ?? 0) + (float) ($value["nanos"] ?? 0) / 1e9;
        }

        return $value;
    }
}
