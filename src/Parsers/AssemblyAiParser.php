<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Subtitle;

class AssemblyAiParser extends SubtitleParser
{
    use WordGrouping;

    public const FORMAT_DATA_KEY = "assemblyai";

    private const MILLISECONDS = 0.001;


    /**
     * Reads the JSON of an AssemblyAI transcript, one cue per utterance, else cues grouped from the words.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $data           = $this->decodeObject($rawSubtitle);
        $words          = $data["words"] ?? null;
        $utterances     = self::listOrEmpty($data["utterances"] ?? null);
        if ($utterances === [] && (!is_array($words) || !array_is_list($words))) {
            throw new ParsingException("The JSON has no \"words\" or \"utterances\" list.");
        }

        $cues = $utterances === [] ? $this->cuesFromWords($this->readWords($words, "words"), "words") : $this->readUtterances($utterances);

        $subtitle = new Subtitle();
        $language = $data["language_code"] ?? null;
        if (is_string($language) && $language !== "") {
            // AssemblyAI writes a region as "en_us". The language metadata holds BCP 47 tags such as "en-US".
            $parts    = explode("_", $language, 2);
            $language = $parts[0] . (isset($parts[1]) ? "-" . strtoupper($parts[1]) : "");
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, $language);
        }
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, array_diff_key($data, array_flip(["text", "words", "utterances"])));

        foreach ($cues as $cue) {
            $subtitle->addCue($cue, false);
        }

        return $subtitle->reIndexCues();
    }


    private function readUtterances(array $utterances): array
    {
        $cues = [];
        foreach ($utterances as $index => $utterance) {
            $path = "utterances[$index]";
            try {
                $start = $this->seconds(is_array($utterance) ? $utterance["start"] ?? null : null, "$path.start", self::MILLISECONDS);
                $end   = $this->seconds($utterance["end"] ?? null, "$path.end", self::MILLISECONDS);
                $text  = $this->text($utterance, "text", $path);
                $words = $this->readWords(self::listOrEmpty($utterance["words"] ?? null), "$path.words");
            } catch (ParsingException $exception) {
                $this->fail($exception, 0, $index, [RawJson::encode($utterance)]);
                continue;
            }

            $formatData = array_diff_key($utterance, array_flip(["start", "end", "text"]));
            $cue        = $this->cue($start, $end, $text, $words, self::speaker($utterance["speaker"] ?? null), $formatData);
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
                $text  = $this->text($word, "text", "{$path}[$index]");
                $start = $this->seconds($word["start"] ?? null, "{$path}[$index].start", self::MILLISECONDS);
                $end   = $this->seconds($word["end"] ?? null, "{$path}[$index].end", self::MILLISECONDS);
            } catch (ParsingException $exception) {
                $this->fail($exception, 0, $index, [RawJson::encode($word)]);
                continue;
            }

            $result[] = ["text" => trim($text), "start" => $start, "end" => $end, "speaker" => self::speaker($word["speaker"] ?? null), "data" => [$word]];
        }

        return $result;
    }
}
