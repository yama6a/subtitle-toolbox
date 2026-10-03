<?php

namespace SubtitleToolbox\Parsers;

use JsonException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WhisperJsonParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = "whisper";

    public const OPTION_WORD_TIMESTAMPS = "OPTION_WORD_TIMESTAMPS";

    /** Writes the "speaker" field of each segment as a <v> tag at the start of its cue, for example <v SPEAKER_00>. */
    public const OPTION_SPEAKER_VOICES = "OPTION_SPEAKER_VOICES";

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

    private bool $wordTimestamps;
    private bool $speakerVoices;


    /**
     * Creates a parser that writes word timestamps and speakers as core markup when OPTION_WORD_TIMESTAMPS and
     * OPTION_SPEAKER_VOICES are true.
     */
    public function __construct(array $options = [])
    {
        $this->wordTimestamps = !empty($options[self::OPTION_WORD_TIMESTAMPS]);
        $this->speakerVoices  = !empty($options[self::OPTION_SPEAKER_VOICES]);
    }


    /**
     * Reads the JSON of the OpenAI transcription API, openai-whisper, faster-whisper, WhisperX and whisper.cpp, one cue per segment.
     */
    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        try {
            // Older whisper.cpp versions split multi-byte characters across tokens and write invalid UTF-8 in token texts.
            $data = json_decode(StringHelpers::removeUtf8Bom($rawSubtitle), true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            throw new ParsingException("The content is not valid JSON: {$exception->getMessage()}.");
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ParsingException("The JSON root must be an object.");
        }

        $segments = match (true) {
            is_array($data["segments"] ?? null)      => $this->readSegments($data["segments"], $data["words"] ?? null),
            is_array($data["transcription"] ?? null) => $this->readTranscription($data["transcription"]),
            default                                  => throw new ParsingException("The JSON has no \"segments\" or \"transcription\" list."),
        };

        $subtitle = new Subtitle();
        $language = $data["language"] ?? $data["result"]["language"] ?? null;
        if (is_string($language) && $language !== "") {
            $subtitle->setMetadata(Subtitle::METADATA_LANGUAGE, self::LANGUAGE_CODES[strtolower($language)] ?? $language);
        }
        $fileData = array_diff_key($data, array_flip(["segments", "transcription", "words", "word_segments", "text"]));
        $subtitle->setFormatData(self::FORMAT_DATA_KEY, $fileData);

        foreach ($segments as [$start, $end, $text, $words, $formatData]) {
            $text = trim($text);
            if ($text === "") {
                continue;
            }

            $markup  = $this->wordTimestamps ? $this->withWordTimestamps($text, $words) : $this->escape($text);
            $speaker = is_string($formatData["speaker"] ?? null) ? trim($formatData["speaker"]) : "";
            if ($this->speakerVoices && $speaker !== "") {
                $markup = "<v " . $this->escape($speaker) . ">" . $markup;
            }

            $cue = new SubtitleCue($start, $end, $markup);
            $subtitle->addCue($cue->setFormatData(self::FORMAT_DATA_KEY, $formatData), false);
        }

        return $subtitle->reIndexCues();
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
                $start = $this->seconds($segment, "start", $path);
                $end   = $this->seconds($segment, "end", $path);
                $text  = $this->text($segment, $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, 0, $index, [$this->encode($segment)]);
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
                    is_string($word["word"] ?? null) ? trim($word["word"]) : "",
                    is_int($word["start"] ?? null) || is_float($word["start"] ?? null) ? round($word["start"], 3) : null,
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
                $start = round($this->seconds($offsets, "from", "$path.offsets") / 1000, 3);
                $end   = round($this->seconds($offsets, "to", "$path.offsets") / 1000, 3);
                $text  = $this->text($segment, $path);
            } catch (ParsingException $exception) {
                $this->fail($exception, 0, $index, [$this->encode($segment)]);
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
                    $words[] = ["", is_int($from) || is_float($from) ? round($from / 1000, 3) : null];
                }
                $words[count($words) - 1][0] .= $tokenText;
            }

            $result[] = [
                $start,
                $end,
                $text,
                array_map(fn (array $word): array => [trim($word[0]), $word[1]], $words),
                array_diff_key($segment, array_flip(["timestamps", "offsets", "text"])),
            ];
        }

        return $result;
    }


    private function seconds(mixed $object, string $key, string $path): float
    {
        $value = is_array($object) ? $object[$key] ?? null : null;
        if (!is_int($value) && !is_float($value)) {
            throw new ParsingException("The field $path.$key must be a number.");
        }

        return round($value, 3);
    }


    private function text(array $segment, string $path): string
    {
        if (!is_string($segment["text"] ?? null)) {
            throw new ParsingException("The field $path.text must be a string.");
        }

        return $segment["text"];
    }


    private function encode(mixed $segment): string
    {
        return json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }


    private function middle(mixed $word): float
    {
        $start = $word["start"] ?? null;
        $end   = $word["end"] ?? $start;

        return is_numeric($start) && is_numeric($end) ? ($start + $end) / 2 : -INF;
    }


    private function withWordTimestamps(string $text, array $words): string
    {
        $markup   = "";
        $copied   = 0;
        $searchAt = 0;
        foreach ($words as [$word, $start]) {
            $position = $word === "" || $start === null ? false : strpos($text, $word, $searchAt);
            if ($position === false) {
                continue;
            }

            $markup  .= $this->escape(substr($text, $copied, $position - $copied)) . "<" . Markup::coreTimestamp($start) . ">";
            $copied   = $position;
            $searchAt = $position + strlen($word);
        }

        return $markup . $this->escape(substr($text, $copied));
    }


    private function escape(string $text): string
    {
        return str_replace(["&", "<", ">"], ["&amp;", "&lt;", "&gt;"], $text);
    }
}
