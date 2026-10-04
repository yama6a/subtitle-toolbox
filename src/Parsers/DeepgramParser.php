<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

final class DeepgramParser extends SubtitleParser
{
    use WordGrouping;

    public const FORMAT_DATA_KEY = Format::Deepgram->value;


    /**
     * Reads the JSON response of the Deepgram pre-recorded audio API, one cue per utterance, else per paragraph
     * sentence, else cues grouped from the words.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $data           = $this->decodeObject($rawSubtitle);
        $results        = $data["results"] ?? null;
        if (!is_array($results) || !is_array($results["channels"] ?? null) || !array_is_list($results["channels"])) {
            throw new ParsingException("The JSON has no \"results.channels\" list.");
        }

        $utterances = self::listOrEmpty($results["utterances"] ?? null);
        $cues       = $utterances === [] ? $this->readChannels($results["channels"]) : $this->readUtterances($utterances);

        $subtitle = new Subtitle();
        $language = $results["channels"][0]["detected_language"] ?? null;
        if (is_string($language) && $language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }
        $fileData = array_diff_key($data, ["results" => true]);
        $other    = array_diff_key($results, array_flip(["channels", "utterances"]));
        if ($other !== []) {
            $fileData["results"] = $other;
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        foreach ($cues as $cue) {
            $subtitle->addCue($cue, false);
        }

        return $subtitle->reIndexCues();
    }


    private function readUtterances(array $utterances): array
    {
        $cues = [];
        foreach ($utterances as $index => $utterance) {
            $path = "results.utterances[$index]";
            try {
                $start = $this->seconds(is_array($utterance) ? $utterance["start"] ?? null : null, "$path.start");
                $end   = $this->seconds($utterance["end"] ?? null, "$path.end");
                $text  = $this->text($utterance, "transcript", $path);
                $words = $this->readWords(self::listOrEmpty($utterance["words"] ?? null), "$path.words");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($utterance)]);
                continue;
            }

            $formatData = array_diff_key($utterance, array_flip(["start", "end", "transcript"]));
            $cue        = $this->cue($start, $end, $text, $words, self::speaker($utterance["speaker"] ?? null), $formatData);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }


    private function readChannels(array $channels): array
    {
        $cues = [];
        foreach ($channels as $channelIndex => $channel) {
            $alternative = is_array($channel) ? $channel["alternatives"][0] ?? null : null;
            if (!is_array($alternative)) {
                continue;
            }

            $path       = "results.channels[$channelIndex].alternatives[0]";
            $words      = $this->readWords(self::listOrEmpty($alternative["words"] ?? null), "$path.words");
            $paragraphs = self::listOrEmpty($alternative["paragraphs"]["paragraphs"] ?? null);
            if ($paragraphs === []) {
                array_push($cues, ...$this->cuesFromWords($words, "words", ["channel" => $channelIndex]));
                continue;
            }

            $wordIndex = 0;
            foreach ($paragraphs as $paragraphIndex => $paragraph) {
                $speaker = self::speaker(is_array($paragraph) ? $paragraph["speaker"] ?? null : null);
                foreach (self::listOrEmpty($paragraph["sentences"] ?? null) as $sentenceIndex => $sentence) {
                    $sentencePath = "$path.paragraphs.paragraphs[$paragraphIndex].sentences[$sentenceIndex]";
                    try {
                        $start = $this->seconds(is_array($sentence) ? $sentence["start"] ?? null : null, "$sentencePath.start");
                        $end   = $this->seconds($sentence["end"] ?? null, "$sentencePath.end");
                        $text  = $this->text($sentence, "text", $sentencePath);
                    } catch (ParsingException $exception) {
                        $this->fail($exception, null, $sentenceIndex, [RawJson::encode($sentence)]);
                        continue;
                    }

                    $sentenceWords = self::wordsBetween($words, $wordIndex, $start, $end);
                    $formatData    = ["channel" => $channelIndex] + ($speaker === null ? [] : ["speaker" => $paragraph["speaker"]]);
                    $cue           = $this->cue($start, $end, $text, $sentenceWords, $speaker,
                                                $formatData + ["words" => array_merge([], ...array_column($sentenceWords, "data"))]);
                    if ($cue !== null) {
                        $cues[] = $cue;
                    }
                }
            }
        }

        return $cues;
    }


    private function readWords(array $words, string $path): array
    {
        $result = [];
        foreach ($words as $index => $word) {
            try {
                $text  = $this->text($word, isset($word["punctuated_word"]) ? "punctuated_word" : "word", "{$path}[$index]");
                $start = $this->seconds($word["start"] ?? null, "{$path}[$index].start");
                $end   = $this->seconds($word["end"] ?? null, "{$path}[$index].end");
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($word)]);
                continue;
            }

            $result[] = ["text" => trim($text), "start" => $start, "end" => $end, "speaker" => self::speaker($word["speaker"] ?? null), "data" => [$word]];
        }

        return $result;
    }
}
