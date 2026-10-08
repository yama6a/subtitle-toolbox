<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Parsers\Options\TranscriptReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;

final class WhisperJsonParser extends SubtitleParser
{
    use WordGrouping;

    protected const FORMAT_OPTIONS = TranscriptReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Whisper->value;

    // TO_LANGUAGE_CODE of openai/whisper, whisper/tokenizer.py. The OpenAI API returns these names in verbose_json.
    private const LANGUAGE_CODES = [
        "english" => "en", "chinese" => "zh", "german" => "de", "spanish" => "es", "russian" => "ru",
        "korean" => "ko", "french" => "fr", "japanese" => "ja", "portuguese" => "pt", "turkish" => "tr",
        "polish" => "pl", "catalan" => "ca", "dutch" => "nl", "arabic" => "ar", "swedish" => "sv", "italian" => "it",
        "indonesian" => "id", "hindi" => "hi", "finnish" => "fi", "vietnamese" => "vi", "hebrew" => "he",
        "ukrainian" => "uk", "greek" => "el", "malay" => "ms", "czech" => "cs", "romanian" => "ro", "danish" => "da",
        "hungarian" => "hu", "tamil" => "ta", "norwegian" => "no", "thai" => "th", "urdu" => "ur", "croatian" => "hr",
        "bulgarian" => "bg", "lithuanian" => "lt", "latin" => "la", "maori" => "mi", "malayalam" => "ml",
        "welsh" => "cy", "slovak" => "sk", "telugu" => "te", "persian" => "fa", "latvian" => "lv", "bengali" => "bn",
        "serbian" => "sr", "azerbaijani" => "az", "slovenian" => "sl", "kannada" => "kn", "estonian" => "et",
        "macedonian" => "mk", "breton" => "br", "basque" => "eu", "icelandic" => "is", "armenian" => "hy",
        "nepali" => "ne", "mongolian" => "mn", "bosnian" => "bs", "kazakh" => "kk", "albanian" => "sq",
        "swahili" => "sw", "galician" => "gl", "marathi" => "mr", "punjabi" => "pa", "sinhala" => "si",
        "khmer" => "km", "shona" => "sn", "yoruba" => "yo", "somali" => "so", "afrikaans" => "af", "occitan" => "oc",
        "georgian" => "ka", "belarusian" => "be", "tajik" => "tg", "sindhi" => "sd", "gujarati" => "gu",
        "amharic" => "am", "yiddish" => "yi", "lao" => "lo", "uzbek" => "uz", "faroese" => "fo",
        "haitian creole" => "ht", "pashto" => "ps", "turkmen" => "tk", "nynorsk" => "nn", "maltese" => "mt",
        "sanskrit" => "sa", "luxembourgish" => "lb", "myanmar" => "my", "tibetan" => "bo", "tagalog" => "tl",
        "malagasy" => "mg", "assamese" => "as", "tatar" => "tt", "hawaiian" => "haw", "lingala" => "ln",
        "hausa" => "ha", "bashkir" => "ba", "javanese" => "jw", "sundanese" => "su", "cantonese" => "yue",
        "burmese" => "my", "valencian" => "ca", "flemish" => "nl", "haitian" => "ht", "letzeburgesch" => "lb",
        "pushto" => "ps", "panjabi" => "pa", "moldavian" => "ro", "moldovan" => "ro", "sinhalese" => "si",
        "castilian" => "es", "mandarin" => "zh",
    ];


    protected static function formatDataKey(): string
    {
        return self::FORMAT_DATA_KEY;
    }


    /**
     * Reads the JSON of the OpenAI transcription API, openai-whisper, faster-whisper, WhisperX and whisper.cpp, one cue per segment.
     */
    protected function read(string $rawSubtitle): Subtitle
    {
        $data = $this->decodeJsonObject($rawSubtitle);

        $segments = match (true) {
            self::isList($data["segments"] ?? null)      => $this->readSegments($data["segments"], $data["words"] ?? null),
            self::isList($data["transcription"] ?? null) => $this->readTranscription($data["transcription"]),
            default                                  => throw new ParsingException("The JSON has no \"segments\" or \"transcription\" list."),
        };

        $cues = [];
        foreach ($segments as [$start, $end, $text, $words, $formatData]) {
            $speaker = is_string($formatData["speaker"] ?? null) ? $formatData["speaker"] : null;
            $cue     = $this->cue($start, $end, $text, $words, $speaker, $formatData);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        $language = $data["language"] ?? $data["result"]["language"] ?? null;
        $language = is_string($language) ? self::LANGUAGE_CODES[strtolower($language)] ?? $language : $language;
        $fileData = array_diff_key($data, array_flip(["segments", "transcription", "words", "word_segments", "text"]));

        return $this->transcript($cues, $language, $fileData);
    }


    private function readSegments(array $segments, mixed $topLevelWords): array
    {
        $topLevelWords = is_array($topLevelWords) ? array_values($topLevelWords) : [];
        $wordIndex     = 0;
        $lastIndex     = array_key_last($segments);
        $result        = [];

        foreach ($segments as $index => $segment) {
            $path = "segments[$index]";
            try {
                $start = $this->number($segment, "start", $path);
                $end   = $this->number($segment, "end", $path);
                $text  = $this->text($segment, "text", $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }
            $words = is_array($segment["words"] ?? null) ? $segment["words"] : [];

            if ($topLevelWords !== []) {
                // The API lists the words of all segments at the top level. A word belongs to the segment that holds its middle.
                while ($wordIndex < count($topLevelWords)
                       && ($index === $lastIndex || $this->middle($topLevelWords[$wordIndex]) <= $end)) {
                    $words[] = $topLevelWords[$wordIndex++];
                }
                $segment["words"] = $words;
            }

            $timedWords = [];
            foreach ($words as $word) {
                $timedWords[] = [
                    "text"  => is_string($word["word"] ?? null) ? trim($word["word"]) : "",
                    "start" => self::isTime($word["start"] ?? null) ? Timecode::roundToMilliseconds($word["start"]) : null,
                ];
            }

            $result[] = [$start, $end, $text, $timedWords, array_diff_key($segment, array_flip(["start", "end", "text"]))];
        }

        return $result;
    }


    private function readTranscription(array $transcription): array
    {
        $result = [];
        foreach ($transcription as $index => $segment) {
            $path    = "transcription[$index]";
            $offsets = is_array($segment) ? $segment["offsets"] ?? null : null;
            try {
                $start = Timecode::roundToMilliseconds($this->number($offsets, "from", "$path.offsets") / 1000);
                $end   = Timecode::roundToMilliseconds($this->number($offsets, "to", "$path.offsets") / 1000);
                $text  = $this->text($segment, "text", $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, null, $index, [RawJson::encode($segment)]);
                continue;
            }

            // A token with a leading space starts a new word, as in should_split_on_word() of whisper.cpp.
            $words = [];
            foreach (is_array($segment["tokens"] ?? null) ? $segment["tokens"] : [] as $token) {
                $tokenText = $token["text"] ?? null;
                if (!is_string($tokenText) || str_starts_with($tokenText, "[_")) {
                    continue;
                }

                if ($words === [] || str_starts_with($tokenText, " ")) {
                    $from    = $token["offsets"]["from"] ?? null;
                    $words[] = ["text" => "", "start" => self::isTime($from) ? Timecode::roundToMilliseconds($from / 1000) : null];
                }
                $words[count($words) - 1]["text"] .= $tokenText;
            }

            $result[] = [
                $start,
                $end,
                $text,
                array_map(fn (array $word): array => ["text" => trim($word["text"]), "start" => $word["start"]], $words),
                array_diff_key($segment, array_flip(["timestamps", "offsets", "text"])),
            ];
        }

        return $result;
    }


    private function number(mixed $object, string $key, string $path): float
    {
        $value = is_array($object) ? $object[$key] ?? null : null;
        if (!self::isTime($value)) {
            throw new ParsingException("The field $path.$key must be a number.");
        }

        return Timecode::roundToMilliseconds($value);
    }


    private function middle(mixed $word): float
    {
        $start = $word["start"] ?? null;
        $end   = $word["end"] ?? $start;

        return is_numeric($start) && is_numeric($end) ? ($start + $end) / 2 : -INF;
    }
}
